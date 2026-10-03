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
define('AF_PRESENTATION_PREFERENCES_TABLE_NAME', 'af_presentation_preferences');
define('AF_ADAPTIVETHEMEFRAMEWORK_VERSION', '0.26.7');

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
        $provider = is_array($providers) ? ($providers[AF_ADAPTIVETHEMEFRAMEWORK_ID] ?? null) : null;
        $callback = is_string($provider)
            ? $provider
            : (is_array($provider) ? (string)($provider['callback'] ?? '') : '');
        $loadWhenDisabled = is_array($provider) && !empty($provider['load_when_disabled']);
        if ($id === '' || $callback === '') {
            continue;
        }
        $enabled = function_exists('af_is_addon_enabled')
            ? af_is_addon_enabled($id)
            : (string)($mybb->settings['af_' . $id . '_enabled'] ?? '0') === '1';
        if (!$enabled && !$loadWhenDisabled) {
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
        foreach (['start_marker_count', 'end_marker_count', 'marker_count', 'variable_count', 'backup_match', 'legacy_disable_match', 'normalized_checksum'] as $field) {
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
    af_adaptivethemeframework_ensure_preferences_schema();
    $GLOBALS['af_theme_switcher_preference_providers']['adaptivethemeframework'] = 'af_adaptivethemeframework_render_theme_preferences';
    af_adaptivethemeframework_register_thread_providers();
    af_adaptivethemeframework_register_post_providers();
    af_adaptivethemeframework_register_profile_providers();
    af_adaptivethemeframework_ucp_seed_navigation();
    af_adaptivethemeframework_modcp_seed_navigation();
    $isPrivateRoute = defined('THIS_SCRIPT') && THIS_SCRIPT === 'private.php';
    if ($isPrivateRoute) {
        af_adaptivethemeframework_register_pm_providers();
    }
    if (is_object($plugins) && method_exists($plugins, 'add_hook')) {
        $plugins->add_hook('pre_output_page', 'af_adaptivethemeframework_mark_page', 100);
        $plugins->add_hook('index_start', 'af_adaptivethemeframework_resolve_index_preferences', 100);
        $plugins->add_hook('misc_start', 'af_adaptivethemeframework_save_presentation_preference', 10);
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
        // Core remains the stock member-list data/action/pagination owner. The
        // late row hook only normalizes presentation values and materializes
        // the same public slots used by the AAS DTO renderer.
        $plugins->add_hook('memberlist_end', 'af_adaptivethemeframework_compose_stock_userlist_page', 1000);
        $plugins->add_hook('memberlist_user', 'af_adaptivethemeframework_compose_stock_user_card', 1000);
        if (defined('THIS_SCRIPT') && THIS_SCRIPT === 'modcp.php') {
            // Core has already built its permission-filtered forum counters at
            // this hook. ATF consumes that state; it never authorizes a route.
            $plugins->add_hook('modcp_start', 'af_adaptivethemeframework_compose_modcp_shell', 1000);
        }
        if (defined('THIS_SCRIPT') && THIS_SCRIPT === 'usercp.php') {
            // Contextual subscription confirmations have no dedicated end
            // hook, so prepare their navigation before core evaluates them.
            $plugins->add_hook('usercp_start', 'af_adaptivethemeframework_compose_ucp_profile_surface', 1000);
            // Each migrated form keeps native core values and processing while
            // adopting the shared navigation context at its final display hook.
            $plugins->add_hook('usercp_end', 'af_adaptivethemeframework_compose_ucp_overview', 1000);
            foreach ([
                'usercp_profile_end', 'usercp_avatar_end', 'usercp_editsig_end',
                'usercp_options_end', 'usercp_password', 'usercp_email', 'usercp_changename_end',
                'usercp_editlists_end', 'usercp_usergroups_end',
                'usercp_subscriptions_end', 'usercp_forumsubscriptions_end',
                'usercp_drafts_end', 'usercp_attachments_end',
            ] as $hook) {
                $plugins->add_hook($hook, 'af_adaptivethemeframework_compose_ucp_profile_surface', 1000);
            }
        }
        if ($isPrivateRoute) {
            // PM rows expose their display participant only while their bit is
            // evaluated.  Tiny uid markers retain that decision and this late
            // pass resolves every distinct participant in one query.
            $plugins->add_hook('pre_output_page', 'af_adaptivethemeframework_render_pm_avatars', 90);
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

/** Create reusable per-user presentation storage without changing MyBB users. */
function af_adaptivethemeframework_ensure_preferences_schema(): bool
{
    global $db;
    if (!is_object($db) || $db->table_exists(AF_PRESENTATION_PREFERENCES_TABLE_NAME)) return true;
    $collation = $db->build_create_table_collation();
    $db->write_query('CREATE TABLE IF NOT EXISTS '.TABLE_PREFIX.AF_PRESENTATION_PREFERENCES_TABLE_NAME." (
        uid int unsigned NOT NULL, preference_key varchar(64) NOT NULL DEFAULT '',
        preference_value varchar(255) NOT NULL DEFAULT '', updated_at int unsigned NOT NULL DEFAULT 0,
        PRIMARY KEY (uid, preference_key), KEY preference_key (preference_key)
    ){$collation}");
    return true;
}

function af_adaptivethemeframework_forum_layout(): string
{
    global $db, $mybb;
    $uid = (int)($mybb->user['uid'] ?? 0);
    if ($uid <= 0 || !is_object($db) || !$db->table_exists(AF_PRESENTATION_PREFERENCES_TABLE_NAME)) return 'full';
    $value = (string)$db->fetch_field($db->simple_select(
        AF_PRESENTATION_PREFERENCES_TABLE_NAME,
        'preference_value',
        "uid='{$uid}' AND preference_key='forum_layout'",
        ['limit' => 1]
    ), 'preference_value');
    return in_array($value, ['full', 'grid'], true) ? $value : 'full';
}

/** Resolve the validated state before the owned index template is rendered. */
function af_adaptivethemeframework_resolve_index_preferences(): void
{
    $GLOBALS['atf_forum_layout'] = af_adaptivethemeframework_forum_layout();
}

/** Provider-owned controls embedded by the theme_switcher host. */
function af_adaptivethemeframework_render_theme_preferences(array $item = []): string
{
    global $lang, $mybb;
    if (empty($mybb->user['uid'])) return '';
    $lang->load('advancedfunctionality_adaptivethemeframework');
    $layout = af_adaptivethemeframework_forum_layout();
    $label = htmlspecialchars_uni((string)($lang->atf_forum_layout ?? 'Forum layout'));
    $full = htmlspecialchars_uni((string)($lang->atf_forum_layout_full ?? 'Full width'));
    $grid = htmlspecialchars_uni((string)($lang->atf_forum_layout_grid ?? 'Grid'));
    $save = htmlspecialchars_uni((string)($lang->atf_presentation_save ?? 'Save'));
    return '<form class="atf-theme-preferences" method="post" action="misc.php?action=atf_presentation_preference">'
        .'<input type="hidden" name="my_post_key" value="'.htmlspecialchars_uni((string)$mybb->post_code).'">'
        .'<fieldset><legend>'.$label.'</legend><label><input type="radio" name="forum_layout" value="full"'.($layout === 'full' ? ' checked' : '').'> '.$full.'</label>'
        .'<label><input type="radio" name="forum_layout" value="grid"'.($layout === 'grid' ? ' checked' : '').'> '.$grid.'</label></fieldset>'
        .'<button type="submit">'.$save.'</button></form>';
}

/** Persist only the submitted, allow-listed preference for registered users. */
function af_adaptivethemeframework_save_presentation_preference(): void
{
    global $db, $mybb;
    if (($mybb->get_input('action') ?? '') !== 'atf_presentation_preference') return;
    if (empty($mybb->user['uid'])) error_no_permission();
    verify_post_check($mybb->get_input('my_post_key'));
    $value = (string)$mybb->get_input('forum_layout');
    if (!in_array($value, ['full', 'grid'], true)) $value = 'full';
    $row = ['uid' => (int)$mybb->user['uid'], 'preference_key' => 'forum_layout',
        'preference_value' => $db->escape_string($value), 'updated_at' => TIME_NOW];
    $db->replace_query(AF_PRESENTATION_PREFERENCES_TABLE_NAME, $row);
    redirect('index.php');
}

/** URL used by every ATF user-card when its supplied avatar is absent/broken. */
function af_adaptivethemeframework_default_avatar_url(): string
{
    global $mybb, $theme;
    $fallback = (string)($mybb->settings['useravatar'] ?? 'images/default_avatar.png');
    $fallback = str_replace('{theme}', (string)($theme['imgdir'] ?? 'images'), $fallback);
    if (!preg_match('~^(?:https?:)?//|^data:|^/~i', $fallback)) {
        $fallback = rtrim((string)($mybb->settings['bburl'] ?? ''), '/') . '/' . ltrim($fallback, '/');
    }
    return $fallback;
}

/** Materialize page slots without wrapping empty provider output. */
function af_adaptivethemeframework_compose_stock_userlist_page(): void
{
    $context = ['surface' => 'stock_memberlist'];
    $GLOBALS['atf_userlist_before_list'] = af_adaptivethemeframework_render_slot('userlist.before_list', $context);
    $GLOBALS['atf_userlist_after_list'] = af_adaptivethemeframework_render_slot('userlist.after_list', $context);
}

/** Preserve core-prepared row values and expose only explicit provider slots. */
function af_adaptivethemeframework_compose_stock_user_card(array &$user): void
{
    $uid = max(0, (int)($user['uid'] ?? 0));
    $context = ['surface' => 'stock_memberlist', 'uid' => $uid, 'user' => $user];
    $user['atf_meta'] = af_adaptivethemeframework_render_slot('userlist.card.meta', $context);
    $user['atf_actions'] = af_adaptivethemeframework_render_slot('userlist.card.actions', $context);
}

/** Compose navigation for every ATF-owned UCP workspace and contextual route. */
function af_adaptivethemeframework_compose_ucp_profile_surface(): void
{
    global $mybb, $lang;
    if (!defined('THIS_SCRIPT') || THIS_SCRIPT !== 'usercp.php' || !is_object($mybb)) {
        return;
    }
    $action = (string)($mybb->input['action'] ?? '');
    if (!in_array($action, ['profile', 'avatar', 'editsig', 'options', 'password', 'email', 'changename', 'editlists', 'usergroups', 'subscriptions', 'forumsubscriptions', 'drafts', 'attachments', 'addsubscription', 'removesubscription'], true)) {
        return;
    }
    $titles = [
        'profile' => 'edit_profile',
        'avatar' => 'change_avatar',
        'editsig' => 'edit_sig',
        'options' => 'edit_options',
        'password' => 'change_password',
        'email' => 'change_email',
        'changename' => 'change_username',
        'editlists' => 'edit_lists',
        'usergroups' => 'group_memberships',
        'subscriptions' => 'subscriptions',
        'forumsubscriptions' => 'forum_subscriptions',
        'drafts' => 'drafts',
        'attachments' => 'attachments_manager',
        'addsubscription' => 'subscribe_to_thread',
        'removesubscription' => ((string)($mybb->input['type'] ?? '') === 'forum' ? 'unsubscribe_from_forum' : 'unsubscribe_from_thread'),
    ];
    $titleKey = $titles[$action];
    $context = af_adaptivethemeframework_ucp_context([
        'route' => 'usercp.php',
        'action' => $action,
        'uid' => (int)($mybb->user['uid'] ?? 0),
        'title' => is_object($lang) ? (string)($lang->{$titleKey} ?? '') : '',
    ], []);
    $GLOBALS['atf_ucp_global_navigation'] = $context['global_navigation'];
    $GLOBALS['atf_ucp_local_navigation'] = $context['local_navigation'];
    $GLOBALS['atf_ucp_context'] = $context;
}

/**
 * Resolve all PM-card avatar markers with one user lookup for the whole page.
 *
 * MyBB's $tofromuid already implements the inbox/sent/draft and multiple-
 * recipient display contract. A zero marker therefore deliberately means the
 * neutral fallback and never becomes a link to an unrelated account.
 */
function af_adaptivethemeframework_render_pm_avatars(string &$page): string
{
    global $db, $lang;

    if (strpos($page, '<atf-pm-avatar ') === false) {
        return $page;
    }

    preg_match_all('~<atf-pm-avatar data-uid="(\d+)"></atf-pm-avatar>~', $page, $matches);
    $uids = array_values(array_unique(array_filter(array_map('intval', $matches[1] ?? []))));
    $users = [];
    if ($uids && is_object($db)) {
        $query = $db->simple_select(
            'users',
            'uid, username, avatar, avatartype',
            'uid IN (' . implode(',', $uids) . ')'
        );
        while ($user = $db->fetch_array($query)) {
            $users[(int)$user['uid']] = $user;
        }
    }

    if (!function_exists('af_avatar_render')) {
        $avatarService = AF_ADDONS . 'advancedposteravatar/advancedposteravatar.php';
        if (is_file($avatarService)) {
            require_once $avatarService;
        }
    }
    $fallbackName = is_object($lang) ? (string)($lang->guest ?? 'Guest') : 'Guest';

    $page = preg_replace_callback(
        '~<atf-pm-avatar data-uid="(\d+)"></atf-pm-avatar>~',
        static function(array $match) use ($users, $fallbackName): string {
            if (!function_exists('af_avatar_render')) {
                return '';
            }
            $uid = (int)$match[1];
            $user = $users[$uid] ?? ['uid' => 0, 'username' => $fallbackName];
            return af_avatar_render($user, 'post', [
                'img_class' => 'atf-pm-card__avatar-image',
                'decorative' => true,
                // The letter-avatar enhancer is not loaded on private.php;
                // use the same renderer's static default-image branch.
                'allow_letter' => false,
            ]);
        },
        $page
    );

    return $page;
}

/**
 * Build the closed context shared by this and future UCP surfaces.
 *
 * Native fragments have already passed through MyBB's permissions, escaping,
 * and plugin hooks. They are transported here, not reconstructed by ATF.
 */
function af_adaptivethemeframework_ucp_context(array $state, array $values): array
{
    $route = af_adaptivethemeframework_ucp_route_context([
        'script' => (string)($state['route'] ?? 'usercp.php'),
        'action' => (string)($state['action'] ?? ''),
        'type' => (string)($state['type'] ?? ''),
        'tid' => (int)($state['tid'] ?? 0),
        'fid' => (int)($state['fid'] ?? 0),
    ]);
    $navigation = af_adaptivethemeframework_ucp_navigation($route);
    $base = [
        'route' => $route,
        'uid' => max(0, (int)($state['uid'] ?? 0)),
        'global_navigation' => '',
        'local_navigation' => '',
        'active_key' => (string)$navigation['current'],
        'title' => (string)($state['title'] ?? ''),
        'notices' => '',
        'content' => array_intersect_key($values, array_flip([
            'avatar', 'username', 'posts', 'posts_day', 'reputation', 'email',
            'regdate', 'usergroup', 'referral_info', 'latest_subscribed',
            'latest_threads', 'latest_warnings', 'user_notepad',
        ])),
        'actions' => '',
    ];
    // Render navigation against the same bounded context providers receive.
    $base['global_navigation'] = af_adaptivethemeframework_render_slot('ucp.global_navigation', $base);
    $base['local_navigation'] = af_adaptivethemeframework_render_slot('ucp.local_navigation', $base);
    $base['notices'] = af_adaptivethemeframework_render_slot('ucp.notice', $base);
    $base['actions'] = af_adaptivethemeframework_render_slot('ucp.actions', $base);
    return $base;
}

/** Materialize the Overview shell slots without exposing $GLOBALS to providers. */
function af_adaptivethemeframework_compose_ucp_overview(): void
{
    global $mybb, $lang;
    if (!defined('THIS_SCRIPT') || THIS_SCRIPT !== 'usercp.php'
        || !is_object($mybb) || (string)($mybb->input['action'] ?? '') !== '') {
        return;
    }
    $names = [
        'avatar', 'username', 'reputation', 'regdate', 'usergroup',
        'referral_info', 'latest_subscribed', 'latest_threads',
        'latest_warnings', 'user_notepad',
    ];
    $values = [];
    foreach ($names as $name) {
        $values[$name] = (string)($GLOBALS[$name] ?? '');
    }
    $values['posts'] = (string)($mybb->user['posts'] ?? '');
    $values['posts_day'] = is_object($lang) ? (string)($lang->posts_day ?? '') : '';
    $values['email'] = (string)($mybb->user['email'] ?? '');

    $context = af_adaptivethemeframework_ucp_context([
        'route' => 'usercp.php',
        'action' => '',
        'uid' => (int)($mybb->user['uid'] ?? 0),
        'title' => is_object($lang) ? (string)($lang->account_summary ?? '') : '',
    ], $values);
    $GLOBALS['atf_ucp_global_navigation'] = $context['global_navigation'];
    $GLOBALS['atf_ucp_local_navigation'] = $context['local_navigation'];
    $GLOBALS['atf_ucp_notices'] = $context['notices'];
    $GLOBALS['atf_ucp_actions'] = $context['actions'];
    $GLOBALS['atf_ucp_before_content'] = af_adaptivethemeframework_render_slot('ucp.before_content', $context);
    $GLOBALS['atf_ucp_content'] = af_adaptivethemeframework_render_slot('ucp.content', $context);
    $GLOBALS['atf_ucp_after_content'] = af_adaptivethemeframework_render_slot('ucp.after_content', $context);
    $GLOBALS['atf_ucp_context'] = $context;
}

/** Register native PM workspace fragments without claiming a private_* template. */
function af_adaptivethemeframework_register_pm_providers(): bool
{
    $providers = [
        ['pm_navigation', 'pm.navigation', static fn(array $context): string =>
            af_adaptivethemeframework_render_pm_navigation($context)],
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
        'folders' => is_array($state['folders'] ?? null) ? $state['folders'] : [],
        'permissions' => [
            'send' => !empty($state['can_send']),
            'track' => !empty($state['can_track']),
        ],
        'native' => $native,
    ];
}

/** Render the PM-owned navigation without transporting the legacy UCP table. */
function af_adaptivethemeframework_render_pm_navigation(array $context): string
{
    global $lang;
    $action = (string)($context['action'] ?? '');
    $folderId = (int)($context['folder']['id'] ?? 0);
    $folderIsCurrent = in_array($action, ['', 'read'], true);
    $current = '';
    if (!empty($context['permissions']['send']) && $action === 'send') {
        $current = 'compose';
    } elseif (in_array($action, ['advanced_search', 'search', 'results', 'do_search'], true)) {
        $current = 'search';
    } elseif (in_array($action, ['tracking', 'stoptracking', 'stopalltracking'], true)) {
        $current = 'tracking';
    } elseif (in_array($action, ['folders', 'do_folders'], true)) {
        $current = 'folders';
    } elseif (in_array($action, ['empty', 'do_empty'], true)) {
        $current = 'empty';
    } elseif (in_array($action, ['export', 'do_export'], true)) {
        $current = 'export';
    }

    $escape = static fn(string $value): string => function_exists('htmlspecialchars_uni')
        ? htmlspecialchars_uni($value)
        : htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $label = static function(string $key) use ($lang, $escape): string {
        $value = is_object($lang) && isset($lang->{$key}) ? (string)$lang->{$key} : $key;
        return $escape($value);
    };
    $link = static function(string $key, string $url, string $text) use (&$current, $escape): string {
        $active = $current === $key;
        return '<li><a class="atf-pm-navigation__item'.($active ? ' is-active' : '').'" href="'
            .$escape($url).'"'.($active ? ' aria-current="page"' : '').'>'.$text.'</a></li>';
    };

    $primary = '';
    if (!empty($context['permissions']['send'])) {
        $primary .= $link('compose', 'private.php?action=send', $label('atf_pm_compose'));
    }
    $unreadLabel = is_object($lang) ? (string)($lang->folder_unread ?? '') : '';
    foreach ((array)($context['folders'] ?? []) as $id => $name) {
        $id = (int)$id;
        // MyBB may expose its historical virtual Unread folder in pmfolders;
        // it has no canonical route of its own and is intentionally omitted.
        if ($id === 1 && $unreadLabel !== '' && (string)$name === $unreadLabel
            && array_key_exists(0, (array)$context['folders'])) {
            continue;
        }
        $key = 'folder-'.$id;
        if ($folderIsCurrent && $folderId === $id && $current === '') {
            $current = $key;
        }
        $primary .= $link($key, 'private.php?fid='.$id, $escape((string)$name));
    }

    $management = '';
    if (!empty($context['permissions']['track'])) {
        $management .= $link('tracking', 'private.php?action=tracking', $label('atf_pm_tracking'));
    }
    $management .= $link('search', 'private.php?action=advanced_search', $label('atf_pm_advanced_search'));
    $management .= $link('folders', 'private.php?action=folders', $label('atf_pm_edit_folders'));
    $management .= $link('empty', 'private.php?action=empty', $label('atf_pm_clear_folders'));
    $management .= $link('export', 'private.php?action=export', $label('atf_pm_export'));

    return '<nav class="atf-pm-navigation" aria-label="'.$label('private_messaging').'">'
        .'<div class="atf-pm-navigation__group"><ul>'.$primary.'</ul></div>'
        .'<div class="atf-pm-navigation__group atf-pm-navigation__group--management"><ul>'.$management.'</ul></div>'
        .'</nav>';
}

/** Materialize the PM slots while leaving every legacy template untouched. */
function af_adaptivethemeframework_compose_pm_workspace(): void
{
    global $mybb, $fid, $foldername, $foldernames, $pm;
    if (!defined('THIS_SCRIPT') || THIS_SCRIPT !== 'private.php') {
        return;
    }
    $action = is_object($mybb) ? (string)($mybb->input['action'] ?? '') : '';
    $uid = is_object($mybb) ? (int)($mybb->user['uid'] ?? 0) : 0;
    $currentUrl = function_exists('get_current_location')
        ? (string)get_current_location()
        : (string)($_SERVER['REQUEST_URI'] ?? '');
    $names = [
        'pmspacebar',
        'limitwarning', 'multipage', 'composelink', 'emptyexportlink',
    ];
    $values = [];
    foreach ($names as $name) {
        $values[$name] = (string)($GLOBALS[$name] ?? '');
    }
    // Core's shared posticons template is intentionally not leased by ATF. On
    // the compose surface it supplies only a row/cell wrapper around the icon
    // controls, so discard that table presentation while retaining the exact
    // permission-filtered controls and their names/values.
    if (isset($GLOBALS['posticons'])) {
        $GLOBALS['posticons'] = (string)preg_replace(
            '~</?(?:table|thead|tbody|tfoot|tr|th|td)\b[^>]*>~i',
            '',
            (string)$GLOBALS['posticons']
        );
    }
    $resolvedFid = (int)($fid ?? ($mybb->input['fid'] ?? 0));
    if ($action === 'read' && isset($pm['folder'])) {
        $resolvedFid = (int)$pm['folder'];
    }
    $context = af_adaptivethemeframework_pm_context([
        'action' => $action,
        'folder_id' => $resolvedFid,
        'folder_name' => (string)($foldername ?? ''),
        'uid' => $uid,
        'current_url' => $currentUrl,
        'folders' => is_array($foldernames ?? null) ? $foldernames : [],
        'can_send' => !empty($mybb->usergroup['cansendpms']),
        'can_track' => !empty($mybb->usergroup['cantrackpms']),
    ], $values);
    $ucpContext = af_adaptivethemeframework_ucp_context([
        'route' => 'private.php', 'action' => $action, 'fid' => $resolvedFid,
        'uid' => $uid, 'title' => '',
    ], []);
    $GLOBALS['atf_ucp_global_navigation'] = $ucpContext['global_navigation'];
    foreach (['navigation', 'quota', 'notice', 'pagination', 'actions',
              'content', 'before_content', 'after_content'] as $name) {
        $GLOBALS['atf_pm_' . $name] = af_adaptivethemeframework_render_slot('pm.' . $name, $context);
    }
    $GLOBALS['atf_pm_context'] = $context;
}

/**
 * Convert the small, permission-filtered MyBB profile fragments to semantic
 * cards.  Core remains the data/permission owner; ATF only replaces the table
 * presentation.
 */
function af_adaptivethemeframework_profile_semantic_rows(string $html, string $mode = 'info'): string
{
    $html = trim($html);
    if ($html === '') {
        return '';
    }

    $items = [];
    if (preg_match_all('~<tr\\b[^>]*>(.*?)</tr>~is', $html, $rows)) {
        foreach ($rows[1] as $rowHtml) {
            if (preg_match("~<t[dh]\\b[^>]*class=[\\\"'][^\\\"']*\\bthead\\b[^\\\"']*[\\\"'][^>]*>~i", $rowHtml)) {
                continue;
            }

            $cells = [];
            if (!preg_match_all('~<t[dh]\\b[^>]*>(.*?)</t[dh]>~is', $rowHtml, $cellMatches)) {
                continue;
            }
            foreach ($cellMatches[1] as $cellHtml) {
                $cellHtml = trim((string)$cellHtml);
                if ($cellHtml !== '') {
                    $cells[] = $cellHtml;
                }
            }
            if (!$cells) {
                continue;
            }

            if ($mode === 'info' && count($cells) >= 2) {
                $label = array_shift($cells);
                $value = implode(' ', $cells);
                $label = preg_replace('~^\\s*<(?:strong|b)\\b[^>]*>(.*?)</(?:strong|b)>\\s*:?\\s*$~is', '$1', $label) ?? $label;
                $items[] = '<div class="atf-profile-native__item">'
                    . '<div class="atf-profile-native__label">' . $label . '</div>'
                    . '<div class="atf-profile-native__value">' . $value . '</div>'
                    . '</div>';
                continue;
            }

            $items[] = '<div class="atf-profile-action-item">' . implode(' ', $cells) . '</div>';
        }
    }

    if (!$items && preg_match_all('~<li\\b[^>]*>(.*?)</li>~is', $html, $listItems)) {
        foreach ($listItems[1] as $itemHtml) {
            $itemHtml = trim((string)$itemHtml);
            if ($itemHtml !== '') {
                $items[] = '<div class="atf-profile-action-item">' . $itemHtml . '</div>';
            }
        }
    }

    if (!$items) {
        $flat = preg_replace('~</?(?:table|tbody|thead|tfoot|tr|td|th|ul|ol)\\b[^>]*>~i', '', $html) ?? $html;
        $flat = trim($flat);
        if ($flat !== '') {
            $items[] = '<div class="' . ($mode === 'info' ? 'atf-profile-native__item atf-profile-native__item--wide' : 'atf-profile-action-item') . '">' . $flat . '</div>';
        }
    }

    if (!$items) {
        return '';
    }

    $class = $mode === 'info' ? 'atf-profile-native__grid' : 'atf-profile-actions__grid';
    return '<div class="' . $class . '">' . implode('', $items) . '</div>';
}

function af_adaptivethemeframework_render_profile_main(array $context): string
{
    $profileFields = af_adaptivethemeframework_profile_semantic_rows(
        (string)($context['native']['profilefields'] ?? ''),
        'info'
    );
    $contacts = af_adaptivethemeframework_profile_semantic_rows(
        (string)($context['native']['contact_details'] ?? ''),
        'info'
    );
    $signature = trim((string)($context['native']['signature'] ?? ''));

    $html = '';
    if ($profileFields !== '' || $contacts !== '') {
        $html .= '<section class="atf-profile-native" aria-label="Дополнительная информация">';
        if ($profileFields !== '') {
            $html .= $profileFields;
        }
        if ($contacts !== '') {
            $html .= $contacts;
        }
        $html .= '</section>';
    }
    if ($signature !== '') {
        $html .= '<section class="atf-profile-signature">'
            . '<div class="atf-profile-signature__title">Подпись</div>'
            . '<div class="atf-profile-signature__body">' . $signature . '</div>'
            . '</section>';
    }

    return $html;
}

function af_adaptivethemeframework_render_profile_actions(array $context): string
{
    $mod = af_adaptivethemeframework_profile_semantic_rows(
        (string)($context['native']['modoptions'] ?? ''),
        'actions'
    );
    $admin = af_adaptivethemeframework_profile_semantic_rows(
        (string)($context['native']['adminoptions'] ?? ''),
        'actions'
    );

    $html = '';
    if ($mod !== '' || $admin !== '') {
        $html .= '<details class="atf-profile-tools">';
        $html .= '<summary class="atf-profile-tools__summary">'
            . '<span>Функции модератора и администратора</span>'
            . '<span class="atf-profile-tools__chevron" aria-hidden="true"></span>'
            . '</summary>';
        $html .= '<div class="atf-profile-tools__body">';
        if ($mod !== '') {
            $html .= '<section class="atf-profile-tools__group"><h3>Модератор</h3>' . $mod . '</section>';
        }
        if ($admin !== '') {
            $html .= '<section class="atf-profile-tools__group"><h3>Администратор</h3>' . $admin . '</section>';
        }
        $html .= '</div></details>';
    }

    $userActions = [];
    foreach ([
        'buddy_options' => 'Друзья',
        'ignore_options' => 'Игнорирование',
        'report_options' => 'Жалоба',
    ] as $key => $label) {
        $content = af_adaptivethemeframework_profile_semantic_rows(
            (string)($context['native'][$key] ?? ''),
            'actions'
        );
        if ($content !== '') {
            $userActions[] = '<section class="atf-profile-user-action">'
                . '<div class="atf-profile-user-action__label">' . htmlspecialchars_uni($label) . '</div>'
                . $content
                . '</section>';
        }
    }
    if ($userActions) {
        $html .= '<div class="atf-profile-user-actions">' . implode('', $userActions) . '</div>';
    }

    return $html;
}

/** Native profile fragments remain permission-owned by MyBB; ATF owns presentation only. */
function af_adaptivethemeframework_register_profile_providers(): bool
{
    $registered = af_adaptivethemeframework_register_component([
        'owner' => 'mybb', 'key' => 'profile_main', 'slot' => 'profile.main',
        'renderer' => 'af_adaptivethemeframework_render_profile_main',
    ]);
    $registered = af_adaptivethemeframework_register_component([
        'owner' => 'mybb', 'key' => 'profile_actions', 'slot' => 'profile.after_content',
        'renderer' => 'af_adaptivethemeframework_render_profile_actions',
    ]) || $registered;
    return $registered;
}

/** A closed, deliberately whitelisted profile context (never $GLOBALS). */
function af_adaptivethemeframework_profile_context(array $member, array $values): array
{
    global $mybb;
    $uid = max(0, (int)($member['uid'] ?? 0));
    $viewerUid = is_object($mybb) ? max(0, (int)($mybb->user['uid'] ?? 0)) : 0;
    $memberKeys = ['uid', 'username', 'usergroup', 'displaygroup', 'avatar', 'usertitle', 'regdate', 'lastactive', 'af_advancedpostcounter'];
    $avatars = function_exists('af_apui_get_profile_avatars')
        ? af_apui_get_profile_avatars($uid, false)
        : ['primary_avatar' => '', 'secondary_avatar' => '', 'display_avatar' => '', 'secondary_avatar_missing' => true];
    return [
        'uid' => $uid,
        'username' => (string)($member['username'] ?? ''),
        'member' => array_intersect_key($member, array_flip($memberKeys)),
        'identity' => array_intersect_key($values, array_flip([
            'formattedname', 'avatar', 'usertitle', 'groupimage', 'userstars',
            'online_status', 'memregdate', 'memlastvisitdate', 'awaybit', 'bannedbit',
        ])),
        'avatars' => $avatars,
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
        'af_apui_secondary_avatar', 'af_apui_display_avatar',
    ];
    $data = ['actions_html' => $actions];
    foreach ($keys as $key) $data[$key] = (string)($post[$key] ?? '');
    return ['pid' => max(0, (int)($post['pid'] ?? 0)),
        'tid' => max(0, (int)($post['tid'] ?? 0)),
        'uid' => max(0, (int)($post['uid'] ?? 0)), 'post' => $data];
}

/** Materialize every post slot without imposing a DOM or changing templates. */
function af_adaptivethemeframework_resolve_post_display_avatar(array $post): string
{
    $uid = max(0, (int)($post['uid'] ?? 0));

    // APF owns the character/secondary avatar. ATF must not depend on
    // AdvancedProfileUI being enabled merely to display that APF-owned value.
    if ($uid > 0 && function_exists('af_apf_get_secondary_avatar')) {
        $secondary = trim((string)af_apf_get_secondary_avatar($uid));
        if ($secondary !== '') {
            $username = (string)($post['username'] ?? '');
            if (function_exists('af_apui_render_avatar_image')) {
                return af_apui_render_avatar_image(
                    $secondary,
                    $username,
                    'af-apui-postbit-avatar__image atf-post__avatar-image'
                );
            }

            return '<img src="' . htmlspecialchars_uni($secondary)
                . '" alt="' . htmlspecialchars_uni($username)
                . '" class="atf-post__avatar-image" loading="lazy" decoding="async">';
        }
    }

    // Keep MyBB's already permission-/setting-aware avatar markup as fallback.
    return (string)($post['useravatar'] ?? '');
}

function af_adaptivethemeframework_compose_postbit(array &$post): void
{
    // The ATF-owned postbit template consumes a presentation value directly.
    // Materialize it here even when APUI is disabled or its postbit hook did
    // not run, so secondary avatars remain an APF -> ATF contract.
    $post['af_apui_display_avatar'] = af_adaptivethemeframework_resolve_post_display_avatar($post);
    $post['af_apui_secondary_avatar'] = '';
    $uid = max(0, (int)($post['uid'] ?? 0));
    if ($uid > 0 && function_exists('af_apf_get_secondary_avatar')) {
        $post['af_apui_secondary_avatar'] = (string)af_apf_get_secondary_avatar($uid);
    }

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

/** Render a query-free native avatar when no optional avatar provider exists. */
function af_adaptivethemeframework_lastposter_avatar_fallback(int $uid, string $username = ''): string
{
    $avatar = htmlspecialchars_uni(af_adaptivethemeframework_default_avatar_url());
    $name = htmlspecialchars_uni($username);
    $image = '<img src="'.$avatar.'" alt="'.$name.'" loading="lazy">';
    if ($uid <= 0) {
        return $image;
    }
    $url = function_exists('get_profile_link') ? get_profile_link($uid) : 'member.php?action=profile&amp;uid='.$uid;
    return '<a href="'.htmlspecialchars_uni((string)$url).'">'.$image.'</a>';
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
            $avatar = af_adaptivethemeframework_render_slot(
                'forum.lastposter_avatar',
                ['fid' => (int)$match[1], 'lastposteruid' => (int)$match[2], 'lastposter' => '']
            );
            return $avatar !== ''
                ? $avatar
                : af_adaptivethemeframework_lastposter_avatar_fallback((int)$match[2]);
        },
        $page
    );

    // Preserve an optional provider verbatim, or replace the server-only
    // marker with the native fallback before HTML reaches the browser.
    $page = (string)preg_replace_callback(
        '~(<div class="atf-topic-card__avatar">)(.*?)(<atf-thread-avatar\s+uid="(\d+)"></atf-thread-avatar>)(</div>)~is',
        static function (array $match): string {
            $provided = trim((string)$match[2]);
            $avatar = $provided !== ''
                ? $provided
                : af_adaptivethemeframework_lastposter_avatar_fallback((int)$match[4]);
            return $match[1].$avatar.$match[5];
        },
        $page
    );

    // The nested stock avatar template remains MyBB-owned. Normalize only an
    // image already rendered inside our card viewport; this adds no lookup,
    // permission decision, or dependency on another addon's DOM.
    if (defined('THIS_SCRIPT') && THIS_SCRIPT === 'memberlist.php') {
        $fallback = htmlspecialchars_uni(af_adaptivethemeframework_default_avatar_url());
        $page = (string)preg_replace_callback(
            '~(<div class="atf-user-card__avatar">)(.*?)(</div>)~is',
            static function (array $match) use ($fallback): string {
                $image = trim($match[2]);
                if ($image === '' || stripos($image, '<img') === false) {
                    $image = '<img class="atf-user-card__image" src="'.$fallback.'" alt="" loading="lazy">';
                } else {
                    $image = (string)preg_replace('~\s+alt=("[^"]*"|\'[^\']*\')~i', '', $image, 1);
                    $image = (string)preg_replace(
                        '~<img\b~i',
                        '<img alt="" onerror="this.onerror=null;this.src=\''.$fallback.'\'"',
                        $image,
                        1
                    );
                }
                return $match[1].$image.$match[3];
            },
            $page
        );
    }

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
                if (defined('THIS_SCRIPT') && THIS_SCRIPT === 'index.php') {
                    $layout = ($GLOBALS['atf_forum_layout'] ?? 'full') === 'grid' ? 'grid' : 'full';
                    $classes[] = 'atf-index-page';
                    $classes[] = 'atf-forum-layout--'.$layout;
                }
                $replacement = 'class=' . $classMatch[1][0] . implode(' ', array_filter($classes)) . $classMatch[1][0];
                $attributes = substr_replace($attributes, $replacement, (int)$classMatch[0][1], strlen($classMatch[0][0]));
            } else {
                $layoutClass = '';
                if (defined('THIS_SCRIPT') && THIS_SCRIPT === 'index.php') {
                    $layout = ($GLOBALS['atf_forum_layout'] ?? 'full') === 'grid' ? 'grid' : 'full';
                    $layoutClass = ' atf-index-page atf-forum-layout--'.$layout;
                }
                $attributes .= ' class="atf-active'.$layoutClass.'"';
            }
            return '<body' . $attributes . '>';
        },
        $page,
        1
    );

    // The two footer slots have one canonical location on every ATF page.
    // Providers still own their markup and behaviour; this layer only gives
    // them a stable, out-of-flow mount point.  Inject at final output time so
    // legacy templates do not need to be patched and ATF-off remains inert.
    if (stripos($page, 'id="atf-footer-composition"') === false
        && stripos($page, '</body') !== false) {
        $context = [
            'script' => defined('THIS_SCRIPT') ? (string)THIS_SCRIPT : '',
            'uid' => isset($GLOBALS['mybb']->user['uid']) ? (int)$GLOBALS['mybb']->user['uid'] : 0,
        ];
        $components = af_adaptivethemeframework_render_slot('footer.components', $context);
        $modals = af_adaptivethemeframework_render_slot('footer.modals', $context);
        $bburl = rtrim((string)($GLOBALS['mybb']->settings['bburl'] ?? ''), '/');
        $modalCompositionScript = htmlspecialchars($bburl
            . '/inc/plugins/advancedfunctionality/addons/adaptivethemeframework/assets/adaptivethemeframework.modals.js?v='
            . rawurlencode(AF_ADAPTIVETHEMEFRAMEWORK_VERSION), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $footer = "\n<div id=\"atf-footer-composition\" class=\"atf-footer-composition\" data-atf-footer-components>"
            . $components . "</div>\n"
            . '<div id="atf-global-modal-host" class="atf-global-modal-host" data-atf-modal-host aria-live="polite">'
            . $modals . "</div>\n"
            . '<script src="' . $modalCompositionScript . '" defer></script>' . "\n";
        $page = (string)preg_replace('~</body\s*>~i', $footer . '</body>', $page, 1);
    }

}

/** The core ModCP entry gate; section predicates deliberately build on it. */
function af_adaptivethemeframework_modcp_can_access(): bool
{
    global $mybb;
    return defined('THIS_SCRIPT') && THIS_SCRIPT === 'modcp.php'
        && is_object($mybb) && (int)($mybb->user['uid'] ?? 0) > 0
        && (int)($mybb->usergroup['canmodcp'] ?? 0) === 1;
}

/** Record a malformed data provider without making the moderator panel fail. */
function af_adaptivethemeframework_modcp_navigation_error(string $provider, string $key, string $reason): bool
{
    $message = sprintf('ATF ModCP navigation provider %s (%s) rejected: %s', $provider ?: 'unknown', $key ?: 'unknown', $reason);
    $GLOBALS['af_adaptivethemeframework_modcp_navigation_errors'][] = $message;
    if (function_exists('error_log')) error_log($message);
    return false;
}

/** Register one typed, data-only ModCP destination. */
function af_adaptivethemeframework_modcp_register_navigation_provider(array $item): bool
{
    $provider = strtolower(trim((string)($item['provider'] ?? '')));
    $key = strtolower(trim((string)($item['key'] ?? '')));
    $parent = strtolower(trim((string)($item['parent'] ?? '')));
    $allowed = ['provider','key','parent','route','label','weight','visibility','active','contextual','children'];
    if (array_diff(array_keys($item), $allowed)) return af_adaptivethemeframework_modcp_navigation_error($provider, $key, 'unsupported field');
    if (!preg_match('~^[a-z][a-z0-9_]*$~', $provider) || !preg_match('~^[a-z][a-z0-9_.-]*$~', $key)) return af_adaptivethemeframework_modcp_navigation_error($provider, $key, 'invalid provider or key');
    if (isset($GLOBALS['af_adaptivethemeframework_modcp_navigation'][$key])) return af_adaptivethemeframework_modcp_navigation_error($provider, $key, 'duplicate key');
    if ($parent !== '' && !isset($GLOBALS['af_adaptivethemeframework_modcp_navigation'][$parent])) return af_adaptivethemeframework_modcp_navigation_error($provider, $key, 'unknown parent');
    $route = $item['route'] ?? null;
    if (!is_array($route) || ($route['script'] ?? '') !== 'modcp.php' || !is_array($route['query'] ?? [])) return af_adaptivethemeframework_modcp_navigation_error($provider, $key, 'invalid route');
    foreach ($route['query'] as $name => $value) {
        if (!is_string($name) || !preg_match('~^[a-z][a-z0-9_]*$~i', $name) || (!is_scalar($value) && $value !== null)) return af_adaptivethemeframework_modcp_navigation_error($provider, $key, 'invalid query');
    }
    if (!preg_match('~^[a-z][a-z0-9_]*$~', (string)($item['label'] ?? ''))) return af_adaptivethemeframework_modcp_navigation_error($provider, $key, 'label must be a language key');
    if (!is_bool($item['visibility'] ?? null) && !is_callable($item['visibility'] ?? null)) return af_adaptivethemeframework_modcp_navigation_error($provider, $key, 'invalid visibility');
    if (!is_callable($item['active'] ?? null)) return af_adaptivethemeframework_modcp_navigation_error($provider, $key, 'invalid active resolver');
    $item += ['weight' => 100, 'contextual' => false, 'children' => []];
    if (!is_array($item['children'])) return af_adaptivethemeframework_modcp_navigation_error($provider, $key, 'children must be definitions');
    $children = $item['children']; unset($item['children']);
    $item['provider']=$provider; $item['key']=$key; $item['parent']=$parent; $item['weight']=(int)$item['weight'];
    $GLOBALS['af_adaptivethemeframework_modcp_navigation'][$key]=$item;
    foreach ($children as $childKey => $child) {
        if (!is_array($child)) return af_adaptivethemeframework_modcp_navigation_error($provider, (string)$childKey, 'child is not a definition');
        $child += ['provider'=>$provider, 'key'=>(string)$childKey, 'parent'=>$key];
        if (!af_adaptivethemeframework_modcp_register_navigation_provider($child)) return false;
    }
    return true;
}

/** Seed native sections with visibility callbacks backed by core-built state. */
function af_adaptivethemeframework_modcp_seed_navigation(): void
{
    if (!empty($GLOBALS['af_adaptivethemeframework_modcp_navigation_seeded'])) return;
    $GLOBALS['af_adaptivethemeframework_modcp_navigation_seeded'] = true;
    $entry=static fn(): bool => af_adaptivethemeframework_modcp_can_access();
    $cap=static fn(string $name, ?string $count=null): callable => static function() use ($name,$count): bool {
        global $mybb;
        if (!af_adaptivethemeframework_modcp_can_access() || empty($mybb->usergroup[$name])) return false;
        return !empty($mybb->usergroup['issupermod']) || $count === null || (int)($GLOBALS[$count] ?? 0) > 0;
    };
    $def=static function(string $key,string $parent,array $query,string $label,int $weight,callable $visible,callable $active,bool $contextual=false): void {
        af_adaptivethemeframework_modcp_register_navigation_provider(['provider'=>'mybb','key'=>$key,'parent'=>$parent,'route'=>['script'=>'modcp.php','query'=>$query],'label'=>$label,'weight'=>$weight,'visibility'=>$visible,'active'=>$active,'contextual'=>$contextual,'children'=>[]]);
    };
    $is=static fn(array $actions): callable => static fn(array $r): bool => in_array($r['action'],$actions,true);
    $def('overview','',[],'atf_modcp_nav_overview',10,$entry,$is(['','do_modnotes']));
    $reports=$cap('canmanagereportedcontent','numreportedposts');
    $def('reports','',['action'=>'reports'],'atf_modcp_nav_reports',20,$reports,$is(['reports','do_reports','allreports']));
    $def('reports.open','reports',['action'=>'reports'],'atf_modcp_nav_open',10,$reports,$is(['reports','do_reports']));
    $def('reports.all','reports',['action'=>'allreports'],'atf_modcp_nav_all',20,$reports,$is(['allreports']));
    $queue=$cap('canmanagemodqueue','atf_modcp_any_queue_scope');
    $GLOBALS['atf_modcp_any_queue_scope']=(int)($GLOBALS['nummodqueuethreads']??0)+(int)($GLOBALS['nummodqueueposts']??0)+(int)($GLOBALS['nummodqueueattach']??0);
    $def('queue','',['action'=>'modqueue'],'atf_modcp_nav_queue',30,$queue,$is(['modqueue','do_modqueue']));
    foreach ([['threads','nummodqueuethreads',31],['posts','nummodqueueposts',32],['attachments','nummodqueueattach',33]] as [$type,$count,$weight]) {
        $visible=static function() use ($count,$type): bool { global $mybb; return af_adaptivethemeframework_modcp_can_access() && !empty($mybb->usergroup['canmanagemodqueue']) && (!empty($mybb->usergroup['issupermod']) || (int)($GLOBALS[$count]??0)>0) && ($type!=='attachments' || !empty($mybb->settings['enableattachments'])); };
        $def('queue.'.$type,'queue',['action'=>'modqueue','type'=>$type],'atf_modcp_nav_'.$type,$weight,$visible,static fn(array $r): bool => in_array($r['action'],['modqueue','do_modqueue'],true)&&$r['queue_type']===$type);
    }
    $users=$cap('caneditprofiles');
    $def('users','',['action'=>'finduser'],'atf_modcp_nav_users',40,$users,$is(['finduser','editprofile','do_editprofile']));
    $def('users.find','users',['action'=>'finduser'],'atf_modcp_nav_find_user',10,$users,$is(['finduser']));
    $def('users.edit','users',['action'=>'editprofile','uid'=>'{uid}'],'atf_modcp_nav_edit_profile',20,static fn(array $r): bool => $users($r)&&$r['uid']>0&&in_array($r['action'],['editprofile','do_editprofile'],true),static fn(array $r): bool => in_array($r['action'],['editprofile','do_editprofile'],true)&&$r['uid']>0,true);
    $warnings=$cap('canviewwarnlogs'); $def('warnings','',['action'=>'warninglogs'],'atf_modcp_nav_warnings',50,$warnings,$is(['warninglogs'])); $def('warnings.log','warnings',['action'=>'warninglogs'],'atf_modcp_nav_log',10,$warnings,$is(['warninglogs']));
    $bans=$cap('canbanusers'); $def('bans','',['action'=>'banning'],'atf_modcp_nav_bans',60,$bans,$is(['banning','liftban','banuser','do_banuser']));
    $def('bans.list','bans',['action'=>'banning'],'atf_modcp_nav_ban_list',10,$bans,$is(['banning','liftban']));
    $def('bans.add','bans',['action'=>'banuser'],'atf_modcp_nav_add_ban',20,$bans,static fn(array $r): bool => in_array($r['action'],['banuser','do_banuser'],true)&&$r['uid']===0);
    $def('bans.edit','bans',['action'=>'banuser','uid'=>'{uid}'],'atf_modcp_nav_edit_ban',30,static fn(array $r): bool => $bans($r)&&$r['uid']>0&&in_array($r['action'],['banuser','do_banuser'],true),static fn(array $r): bool => in_array($r['action'],['banuser','do_banuser'],true)&&$r['uid']>0,true);
    $ips=$cap('canuseipsearch'); $def('iptools','',['action'=>'ipsearch'],'atf_modcp_nav_ip_tools',70,$ips,$is(['ipsearch','iplookup'])); $def('iptools.search','iptools',['action'=>'ipsearch'],'atf_modcp_nav_ip_search',10,$ips,$is(['ipsearch','iplookup']));
    $ann=$cap('canmanageannounce','numannouncements'); $def('announcements','',['action'=>'announcements'],'atf_modcp_nav_announcements',80,$ann,$is(['announcements','new_announcement','do_new_announcement','edit_announcement','do_edit_announcement','delete_announcement','do_delete_announcement']));
    $def('announcements.list','announcements',['action'=>'announcements'],'atf_modcp_nav_announcement_list',10,$ann,$is(['announcements','delete_announcement','do_delete_announcement']));
    $announcementScope=static function(array $r) use ($ann): bool { global $mybb; return $ann($r) && (($r['fid']===-1 && !empty($mybb->usergroup['issupermod'])) || ($r['fid']>0 && function_exists('is_moderator') && is_moderator($r['fid'],'canmanageannouncements'))); };
    $def('announcements.add','announcements',['action'=>'new_announcement','fid'=>'{fid}'],'atf_modcp_nav_add_announcement',20,$announcementScope,$is(['new_announcement','do_new_announcement']));
    $def('announcements.edit','announcements',['action'=>'edit_announcement','aid'=>'{aid}'],'atf_modcp_nav_edit_announcement',30,static fn(array $r): bool => $ann($r)&&$r['aid']>0&&in_array($r['action'],['edit_announcement','do_edit_announcement'],true),static fn(array $r): bool => in_array($r['action'],['edit_announcement','do_edit_announcement'],true)&&$r['aid']>0,true);
    $logs=$cap('canviewmodlogs','nummodlogs'); $def('modlogs','',['action'=>'modlogs'],'atf_modcp_nav_modlogs',90,$logs,$is(['modlogs']));
}

/** Normalize action and typed request values solely for rendering/active state. */
function af_adaptivethemeframework_modcp_route_context(array $context=[]): array
{
    global $mybb;
    $value=static fn(string $key) => array_key_exists($key,$context) ? $context[$key] : (is_object($mybb) ? ($mybb->input[$key]??'') : ($_REQUEST[$key]??''));
    $action=(string)$value('action'); $type=(string)$value('type');
    if ($action==='do_modqueue') {
        $found=[]; foreach (['threads','posts','attachments'] as $candidate) if (is_array($value($candidate)) && $value($candidate)!==[]) $found[]=$candidate;
        $type=count($found)===1 ? $found[0] : '';
    }
    if (!in_array($type,['threads','posts','attachments'],true)) $type='';
    $integer=static fn($raw): int => filter_var($raw,FILTER_VALIDATE_INT)!==false ? (int)$raw : 0;
    return ['script'=>strtolower(basename((string)($context['script']??(defined('THIS_SCRIPT')?THIS_SCRIPT:'')))),'action'=>$action,'queue_type'=>$type,'uid'=>max(0,$integer($value('uid'))),'aid'=>max(0,$integer($value('aid'))),'fid'=>$integer($value('fid'))];
}

/** Resolve visible items and one deepest active key; unknown actions stay neutral. */
function af_adaptivethemeframework_modcp_navigation(array $context=[]): array
{
    af_adaptivethemeframework_modcp_seed_navigation(); $route=af_adaptivethemeframework_modcp_route_context($context); $items=[];$matches=[];
    if ($route['script']!=='modcp.php' || !af_adaptivethemeframework_modcp_can_access()) return ['items'=>[],'current'=>'','route'=>$route];
    foreach (($GLOBALS['af_adaptivethemeframework_modcp_navigation']??[]) as $key=>$item) {
        try {$visible=is_callable($item['visibility'])?(bool)$item['visibility']($route,$item):$item['visibility']===true;} catch(Throwable $e){af_adaptivethemeframework_modcp_navigation_error($item['provider'],$key,'visibility failed: '.$e->getMessage());$visible=false;}
        if(!$visible)continue; $items[$key]=$item;
        try {if((bool)$item['active']($route,$item))$matches[$key]=substr_count($key,'.')+1;} catch(Throwable $e){af_adaptivethemeframework_modcp_navigation_error($item['provider'],$key,'active failed: '.$e->getMessage());}
    }
    arsort($matches);$current=(string)(array_key_first($matches)??'');
    foreach($items as $key=>&$item){$item['current']=$key===$current;$item['is_active']=$item['current']||($current!==''&&str_starts_with($current,$key.'.'));}unset($item);
    uasort($items,static fn($a,$b)=>[$a['weight'],$a['key']]<=>[$b['weight'],$b['key']]);
    return ['items'=>$items,'current'=>$current,'route'=>$route];
}

function af_adaptivethemeframework_render_modcp_navigation(string $level,array $context=[]): string
{
    $navigation=af_adaptivethemeframework_modcp_navigation($context);
    if($level==='local' && $navigation['current']==='') return '';
    $parent=$navigation['current']===''?'':explode('.',$navigation['current'],2)[0];$links='';
    foreach($navigation['items'] as $item){if(($level==='global'&&$item['parent']!=='')||($level==='local'&&$item['parent']!==$parent))continue;
        $query=$item['route']['query'];foreach($query as &$v){if($v==='{uid}')$v=$navigation['route']['uid'];elseif($v==='{aid}')$v=$navigation['route']['aid'];elseif($v==='{fid}')$v=$navigation['route']['fid'];}unset($v);
        $url='modcp.php'.($query?'?'.http_build_query($query,'','&',PHP_QUERY_RFC3986):'');$label=af_adaptivethemeframework_ucp_label($item['label']);
        $links.='<li class="atf-ucp-navigation__item'.($item['is_active']?' is-active':'').'"><a href="'.htmlspecialchars_uni($url).'"'.($item['current']?' aria-current="page"':'').'><span>'.$label.'</span></a></li>';
    }
    if($links==='')return '';$label=$level==='global'?'atf_modcp_navigation':'atf_modcp_section_navigation';
    return '<nav class="atf-ucp-navigation atf-ucp-navigation--'.$level.' atf-modcp-navigation atf-modcp-navigation--'.$level.'" aria-label="'.af_adaptivethemeframework_ucp_label($label).'"><ul>'.$links.'</ul></nav>';
}

/** Materialize the shell after core has calculated every scoped-forum count. */
function af_adaptivethemeframework_compose_modcp_shell(): void
{
    global $lang;
    if(!af_adaptivethemeframework_modcp_can_access())return;
    if (is_object($lang)) $lang->load('advancedfunctionality_adaptivethemeframework');
    // Seeding happens during global.php, before modcp.php derives these counts;
    // refresh the aggregate consumed by the Queue parent now.
    $GLOBALS['atf_modcp_any_queue_scope']=(int)($GLOBALS['nummodqueuethreads']??0)+(int)($GLOBALS['nummodqueueposts']??0)+(int)($GLOBALS['nummodqueueattach']??0);
    $navigation=af_adaptivethemeframework_modcp_navigation();$route=$navigation['route'];
    $titles=[''=>'atf_modcp_nav_overview','do_modnotes'=>'atf_modcp_nav_overview','reports'=>'atf_modcp_nav_reports','do_reports'=>'atf_modcp_nav_reports','allreports'=>'atf_modcp_nav_all','modqueue'=>'atf_modcp_nav_queue','do_modqueue'=>'atf_modcp_nav_queue','finduser'=>'atf_modcp_nav_find_user','editprofile'=>'atf_modcp_nav_edit_profile','do_editprofile'=>'atf_modcp_nav_edit_profile','warninglogs'=>'atf_modcp_nav_warnings','ipsearch'=>'atf_modcp_nav_ip_search','iplookup'=>'atf_modcp_nav_ip_search','banning'=>'atf_modcp_nav_ban_list','liftban'=>'atf_modcp_nav_ban_list','banuser'=>'atf_modcp_nav_add_ban','do_banuser'=>'atf_modcp_nav_add_ban','announcements'=>'atf_modcp_nav_announcement_list','new_announcement'=>'atf_modcp_nav_add_announcement','do_new_announcement'=>'atf_modcp_nav_add_announcement','edit_announcement'=>'atf_modcp_nav_edit_announcement','do_edit_announcement'=>'atf_modcp_nav_edit_announcement','delete_announcement'=>'atf_modcp_nav_announcement_list','do_delete_announcement'=>'atf_modcp_nav_announcement_list','modlogs'=>'atf_modcp_nav_modlogs'];
    if(in_array($route['action'],['banuser','do_banuser'],true)&&$route['uid']>0)$titles[$route['action']]='atf_modcp_nav_edit_ban';
    $titleKey=$titles[$route['action']]??'atf_modcp_title';$context=['route'=>$route,'active_key'=>$navigation['current']];
    $GLOBALS['atf_modcp_title']=is_object($lang)?(string)($lang->{$titleKey}??$lang->modcp??''):'';
    $GLOBALS['atf_modcp_global_navigation']=af_adaptivethemeframework_render_slot('modcp.global_navigation',$context);
    $GLOBALS['atf_modcp_local_navigation']=af_adaptivethemeframework_render_slot('modcp.local_navigation',$context);
    foreach(['notice','actions','before_content','after_content'] as $slot)$GLOBALS['atf_modcp_'.$slot]=af_adaptivethemeframework_render_slot('modcp.'.$slot,$context);
}

/** Semantic icons accepted from UCP navigation providers. */
function af_adaptivethemeframework_ucp_icon_tokens(): array
{
    return ['home', 'user', 'sliders', 'shield', 'users', 'bookmark', 'file', 'envelope', 'bell'];
}

/** Record one bad provider definition without making the User CP unavailable. */
function af_adaptivethemeframework_ucp_navigation_error(string $provider, string $key, string $reason): bool
{
    $message = sprintf('ATF UCP navigation provider %s (%s) rejected: %s', $provider ?: 'unknown', $key ?: 'unknown', $reason);
    $GLOBALS['af_adaptivethemeframework_ucp_navigation_errors'][] = $message;
    if (function_exists('error_log')) error_log($message);
    return false;
}

/** Validate and register one data-only UCP navigation item. */
function af_adaptivethemeframework_ucp_register_navigation_provider(array $item): bool
{
    $provider = strtolower(trim((string)($item['provider'] ?? '')));
    $key = strtolower(trim((string)($item['key'] ?? '')));
    $parent = strtolower(trim((string)($item['parent'] ?? '')));
    $allowedFields = ['key','parent','route','label','icon','weight','visibility','active','children','provider','badge','external','meta'];
    if (array_diff(array_keys($item), $allowedFields)) {
        return af_adaptivethemeframework_ucp_navigation_error($provider, $key, 'unsupported field');
    }
    if (!preg_match('~^[a-z][a-z0-9_]*$~', $provider) || !preg_match('~^[a-z][a-z0-9_.-]*$~', $key)) {
        return af_adaptivethemeframework_ucp_navigation_error($provider, $key, 'invalid provider or key');
    }
    if (isset($GLOBALS['af_adaptivethemeframework_ucp_navigation'][$key])) {
        return af_adaptivethemeframework_ucp_navigation_error($provider, $key, 'duplicate key');
    }
    if ($parent !== '' && !isset($GLOBALS['af_adaptivethemeframework_ucp_navigation'][$parent])) {
        return af_adaptivethemeframework_ucp_navigation_error($provider, $key, 'unknown parent');
    }
    $route = $item['route'] ?? null;
    $scripts = ['usercp.php', 'private.php', 'member.php'];
    if (!is_array($route) || !in_array($route['script'] ?? '', $scripts, true)) {
        return af_adaptivethemeframework_ucp_navigation_error($provider, $key, 'invalid script');
    }
    $query = $route['query'] ?? [];
    if (!is_array($query)) return af_adaptivethemeframework_ucp_navigation_error($provider, $key, 'invalid query structure');
    foreach ($query as $name => $value) {
        if (!is_string($name) || !preg_match('~^[a-z][a-z0-9_]*$~i', $name) || (!is_scalar($value) && $value !== null)) {
            return af_adaptivethemeframework_ucp_navigation_error($provider, $key, 'invalid query structure');
        }
    }
    $label = $item['label'] ?? '';
    if (!is_string($label) || !preg_match('~^[a-z][a-z0-9_]*$~', $label)) {
        return af_adaptivethemeframework_ucp_navigation_error($provider, $key, 'label must be a language key');
    }
    $icon = (string)($item['icon'] ?? '');
    if ($icon !== '' && !in_array($icon, af_adaptivethemeframework_ucp_icon_tokens(), true)) {
        return af_adaptivethemeframework_ucp_navigation_error($provider, $key, 'invalid icon token');
    }
    $visibility = $item['visibility'] ?? true;
    if (!is_bool($visibility) && !is_callable($visibility)) {
        return af_adaptivethemeframework_ucp_navigation_error($provider, $key, 'invalid visibility callback');
    }
    $active = $item['active'] ?? [];
    if (!is_array($active) && !is_callable($active)) {
        return af_adaptivethemeframework_ucp_navigation_error($provider, $key, 'invalid active resolver');
    }
    if (is_array($active)) {
        foreach (['scripts', 'actions'] as $field) {
            if (isset($active[$field]) && (!is_array($active[$field]) || array_filter($active[$field], 'is_string') !== $active[$field])) {
                return af_adaptivethemeframework_ucp_navigation_error($provider, $key, 'invalid active resolver');
            }
        }
        if (isset($active['context']) && !is_callable($active['context'])) {
            return af_adaptivethemeframework_ucp_navigation_error($provider, $key, 'invalid active context');
        }
    }
    if (isset($item['children']) && !is_array($item['children'])) {
        return af_adaptivethemeframework_ucp_navigation_error($provider, $key, 'children must be definitions');
    }
    $children = $item['children'] ?? [];
    unset($item['children']);
    $item += ['weight' => 100, 'badge' => null, 'external' => false, 'meta' => []];
    $item['key'] = $key; $item['parent'] = $parent; $item['provider'] = $provider;
    $item['route'] = ['script' => $route['script'], 'query' => $query];
    $item['weight'] = (int)$item['weight']; $item['children'] = [];
    $GLOBALS['af_adaptivethemeframework_ucp_navigation'][$key] = $item;
    if ($parent !== '') $GLOBALS['af_adaptivethemeframework_ucp_navigation'][$parent]['children'][] = $key;
    foreach ($children as $childKey => $child) {
        if (!is_array($child)) { af_adaptivethemeframework_ucp_navigation_error($provider, (string)$childKey, 'child is not a definition'); continue; }
        $child['key'] = isset($child['key']) ? $child['key'] : $key . '.' . $childKey;
        $child['parent'] = $key; $child['provider'] = $provider;
        af_adaptivethemeframework_ucp_register_navigation_provider($child);
    }
    return true;
}

function af_adaptivethemeframework_ucp_can_access(): bool
{
    global $mybb;
    return (int)($mybb->user['uid'] ?? 0) > 0 && !empty($mybb->usergroup['canusercp']);
}
function af_adaptivethemeframework_ucp_signature_visible(): bool
{
    global $mybb;
    return !empty($mybb->usergroup['canusesig'])
        && ((int)($mybb->usergroup['canusesigxposts'] ?? 0) === 0
            || (int)($mybb->user['postnum'] ?? 0) >= (int)$mybb->usergroup['canusesigxposts']);
}

/** Seed the immutable MyBB hierarchy. Addons can safely register after this call. */
function af_adaptivethemeframework_ucp_seed_navigation(): void
{
    if (!empty($GLOBALS['af_adaptivethemeframework_ucp_navigation_seeded'])) return;
    $GLOBALS['af_adaptivethemeframework_ucp_navigation_seeded'] = true;
    $access = 'af_adaptivethemeframework_ucp_can_access';
    $def = static function(string $key, string $parent, string $script, array $query, string $label, string $icon, int $weight, $visibility, array $actions): void {
        af_adaptivethemeframework_ucp_register_navigation_provider(compact('key','parent','label','icon','weight','visibility') + [
            'provider'=>'mybb', 'route'=>['script'=>$script,'query'=>$query],
            'active'=>['scripts'=>[$script], 'actions'=>$actions], 'children'=>[],
        ]);
    };
    $def('overview','', 'usercp.php',[], 'atf_ucp_nav_overview','home',10,$access,['','do_notepad']);
    $def('profile','', 'usercp.php',['action'=>'profile'], 'atf_ucp_nav_profile','user',20,$access,['profile','do_profile','avatar','do_avatar','changename','do_changename','editsig','do_editsig']);
    $def('profile.edit','profile','usercp.php',['action'=>'profile'],'ucp_nav_edit_profile','user',10,$access,['profile','do_profile']);
    // The route is a native MyBB UCP surface. Keep it discoverable in the
    // profile navigation and let MyBB enforce the actual avatar restrictions
    // when the page is opened.
    $def('profile.avatar','profile','usercp.php',['action'=>'avatar'],'ucp_nav_change_avatar','user',20,$access,['avatar','do_avatar']);
    $def('profile.username','profile','usercp.php',['action'=>'changename'],'atf_ucp_nav_username','user',30,static function(): bool { global $mybb; return af_adaptivethemeframework_ucp_can_access() && (int)($mybb->usergroup['canchangename'] ?? 0) !== 0; },['changename','do_changename']);
    $def('profile.signature','profile','usercp.php',['action'=>'editsig'],'ucp_nav_edit_sig','file',40,'af_adaptivethemeframework_ucp_signature_visible',['editsig','do_editsig']);
    $def('profile.public','profile','member.php',['action'=>'profile','uid'=>'{uid}'],'atf_ucp_nav_public_profile','user',50,$access,[]);
    $GLOBALS['af_adaptivethemeframework_ucp_navigation']['profile.public']['external'] = true;
    $def('preferences','', 'usercp.php',['action'=>'options'],'atf_ucp_nav_preferences','sliders',30,$access,['options','do_options']);
    $def('preferences.general','preferences','usercp.php',['action'=>'options'],'atf_ucp_nav_general_preferences','sliders',10,$access,['options','do_options']);
    $def('security','', 'usercp.php',['action'=>'password'],'atf_ucp_nav_security','shield',40,$access,['password','do_password','email','do_email']);
    $def('security.password','security','usercp.php',['action'=>'password'],'atf_ucp_nav_password','shield',10,$access,['password','do_password']);
    $def('security.email','security','usercp.php',['action'=>'email'],'atf_ucp_nav_email','envelope',20,$access,['email','do_email']);
    $def('social','', 'usercp.php',['action'=>'editlists'],'atf_ucp_nav_social','users',50,$access,['editlists','do_editlists','acceptrequest','declinerequest','cancelrequest','usergroups']);
    $def('social.lists','social','usercp.php',['action'=>'editlists'],'atf_ucp_nav_buddy_ignore','users',10,$access,['editlists','do_editlists','acceptrequest','declinerequest','cancelrequest']);
    $def('social.groups','social','usercp.php',['action'=>'usergroups'],'atf_ucp_nav_group_memberships','users',20,$access,['usergroups']);
    $def('subscriptions','', 'usercp.php',['action'=>'subscriptions'],'atf_ucp_nav_subscriptions','bookmark',60,$access,['subscriptions','do_subscriptions','forumsubscriptions','addsubscription','do_addsubscription','removesubscription','removesubscriptions']);
    $subscriptionContext = static function(array $route, array $item): bool {
        $action=$route['action']; if (!in_array($action,['addsubscription','do_addsubscription','removesubscription','removesubscriptions'],true)) return true;
        $type=$route['type']; $tid=$route['tid']; $fid=$route['fid'];
        return $item['key']==='subscriptions.forums'
            ? ($type==='forum' && $fid>0 && $tid===0)
            : (in_array($type, ['', 'thread'], true) && $tid>0 && $fid===0);
    };
    af_adaptivethemeframework_ucp_register_navigation_provider(['provider'=>'mybb','key'=>'subscriptions.threads','parent'=>'subscriptions','route'=>['script'=>'usercp.php','query'=>['action'=>'subscriptions']],'label'=>'atf_ucp_nav_threads','icon'=>'bookmark','weight'=>10,'visibility'=>$access,'active'=>['scripts'=>['usercp.php'],'actions'=>['subscriptions','do_subscriptions','addsubscription','do_addsubscription','removesubscription','removesubscriptions'],'context'=>$subscriptionContext],'children'=>[]]);
    af_adaptivethemeframework_ucp_register_navigation_provider(['provider'=>'mybb','key'=>'subscriptions.forums','parent'=>'subscriptions','route'=>['script'=>'usercp.php','query'=>['action'=>'forumsubscriptions']],'label'=>'atf_ucp_nav_forums','icon'=>'bookmark','weight'=>20,'visibility'=>$access,'active'=>['scripts'=>['usercp.php'],'actions'=>['forumsubscriptions','addsubscription','do_addsubscription','removesubscription','removesubscriptions'],'context'=>$subscriptionContext],'children'=>[]]);
    $def('content','', 'usercp.php',['action'=>'drafts'],'atf_ucp_nav_content','file',70,$access,['drafts','do_drafts','attachments','do_attachments']);
    $def('content.drafts','content','usercp.php',['action'=>'drafts'],'atf_ucp_nav_drafts','file',10,$access,['drafts','do_drafts']);
    $def('content.attachments','content','usercp.php',['action'=>'attachments'],'atf_ucp_nav_attachments','file',20,static function(): bool { global $mybb; return af_adaptivethemeframework_ucp_can_access() && !empty($mybb->settings['enableattachments']); },['attachments','do_attachments']);
    $def('messages','', 'private.php',[], 'atf_ucp_nav_messages','envelope',80,static function(): bool { global $mybb; return (int)($mybb->user['uid']??0)>0 && !empty($mybb->settings['enablepms']) && !empty($mybb->usergroup['canusepms']); },[]);
    foreach (($GLOBALS['af_adaptivethemeframework_pending_ucp_navigation'] ?? []) as $pending) {
        if (is_array($pending)) af_adaptivethemeframework_ucp_register_navigation_provider($pending);
    }
    unset($GLOBALS['af_adaptivethemeframework_pending_ucp_navigation']);
}

/** Normalize request input to the small typed context used by active rules. */
function af_adaptivethemeframework_ucp_route_context(array $context = []): array
{
    global $mybb;
    $input = static function(string $key) use ($context, $mybb) { return array_key_exists($key,$context) ? $context[$key] : (is_object($mybb) ? $mybb->get_input($key) : ($_REQUEST[$key] ?? '')); };
    return ['script'=>strtolower(basename((string)($context['script'] ?? (defined('THIS_SCRIPT') ? THIS_SCRIPT : '')))),
        'action'=>(string)$input('action'), 'type'=>(string)$input('type'),
        'tid'=>max(0,(int)$input('tid')), 'fid'=>max(0,(int)$input('fid'))];
}

/** Return visible data with exactly one deepest current item and active ancestors. */
function af_adaptivethemeframework_ucp_navigation(array $context = []): array
{
    af_adaptivethemeframework_ucp_seed_navigation(); $route=af_adaptivethemeframework_ucp_route_context($context);
    $items=[]; $matches=[];
    foreach (($GLOBALS['af_adaptivethemeframework_ucp_navigation']??[]) as $key=>$item) {
        try { $visible=is_callable($item['visibility']) ? (bool)call_user_func($item['visibility'],$route,$item) : $item['visibility']===true; }
        catch (Throwable $e) { af_adaptivethemeframework_ucp_navigation_error($item['provider'],$key,'visibility failed: '.$e->getMessage()); $visible=false; }
        if (!$visible) continue;
        $items[$key]=$item; $rule=$item['active']; $matched=false;
        try {
            if (is_callable($rule)) $matched=(bool)$rule($route,$item);
            else $matched=in_array($route['script'],$rule['scripts']??[$item['route']['script']],true)
                && in_array($route['action'],$rule['actions']??[],true)
                && (!isset($rule['context']) || $rule['context']($route,$item));
        } catch (Throwable $e) { af_adaptivethemeframework_ucp_navigation_error($item['provider'],$key,'active resolver failed: '.$e->getMessage()); }
        if ($matched) $matches[$key]=substr_count($key,'.')+1;
    }
    if ($route['script']==='private.php' && isset($items['messages'])) $matches['messages']=1;
    arsort($matches); $current=(string)(array_key_first($matches)??'');
    foreach ($items as $key=>&$item) { $item['current']=$key===$current; $item['is_active']=$item['current'] || ($current!=='' && str_starts_with($current,$key.'.')); }
    unset($item);
    uasort($items,static fn($a,$b)=>[$a['weight'],$a['key']]<=>[$b['weight'],$b['key']]);
    return ['items'=>$items,'current'=>$current,'route'=>$route];
}

function af_adaptivethemeframework_ucp_label(string $label): string
{
    global $lang; $value=(is_object($lang) && isset($lang->{$label})) ? (string)$lang->{$label} : $label;
    return htmlspecialchars_uni($value);
}
function af_adaptivethemeframework_ucp_url(array $item): string
{
    global $mybb; $query=$item['route']['query'];
    foreach ($query as &$value) if ($value==='{uid}') $value=(int)($mybb->user['uid']??0); unset($value);
    $base=rtrim((string)($mybb->settings['bburl']??''),'/').'/'.$item['route']['script'];
    return htmlspecialchars_uni($base.($query ? '?'.http_build_query($query,'','&',PHP_QUERY_RFC3986) : ''));
}

/** Render either stable Level 1 domains or children of its active domain. */
function af_adaptivethemeframework_render_ucp_navigation(string $level, array $context = []): string
{
    $navigation=af_adaptivethemeframework_ucp_navigation($context); $items=$navigation['items']; $parent='';
    if ($level==='local' && $navigation['current']!=='') $parent=explode('.',$navigation['current'],2)[0];
    $links='';
    foreach ($items as $item) {
        if (($level==='global' && $item['parent']!=='') || ($level==='local' && $item['parent']!==$parent)) continue;
        $classes=['atf-ucp-navigation__item']; if ($item['is_active']) $classes[]='is-active';
        $attrs=$item['current'] ? ' aria-current="page"' : '';
        if (!empty($item['external'])) $attrs.=' rel="external"';
        $badge=$item['badge'];
        try { if (is_callable($badge)) $badge=$badge($context,$item); }
        catch (Throwable $e) { af_adaptivethemeframework_ucp_navigation_error($item['provider'],$item['key'],'badge failed: '.$e->getMessage()); $badge=null; }
        $badge=($badge===null||$badge==='') ? '' : '<span class="atf-ucp-navigation__badge">'.htmlspecialchars_uni((string)$badge).'</span>';
        $icon=$item['icon']==='' ? '' : '<span class="atf-ucp-navigation__icon" data-icon="'.htmlspecialchars_uni($item['icon']).'" aria-hidden="true"></span>';
        $links.='<li class="'.implode(' ',$classes).'"><a href="'.af_adaptivethemeframework_ucp_url($item).'"'.$attrs.'>'.$icon.'<span>'.af_adaptivethemeframework_ucp_label($item['label']).'</span>'.$badge.'</a></li>';
    }
    if ($links==='') return '';
    $label=$level==='global' ? 'atf_ucp_navigation' : 'atf_ucp_section_navigation';
    return '<nav class="atf-ucp-navigation atf-ucp-navigation--'.$level.'" aria-label="'.af_adaptivethemeframework_ucp_label($label).'"><ul>'.$links.'</ul></nav>';
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
        'modcp.global_navigation', 'modcp.local_navigation',
        'modcp.before_content', 'modcp.after_content', 'modcp.notice', 'modcp.actions',
        'header.primary_navigation', 'header.secondary_navigation',
        'header.user_navigation', 'header.assets', 'footer.components',
        'footer.modals',
        'pm.navigation', 'pm.quota', 'pm.notice', 'pm.pagination',
        'pm.actions', 'pm.content', 'pm.before_content', 'pm.after_content',
        'ucp.global_navigation', 'ucp.local_navigation',
        'ucp.before_content', 'ucp.content', 'ucp.after_content',
        'ucp.notice', 'ucp.actions',
        'userlist.before_list', 'userlist.card.meta',
        'userlist.card.actions', 'userlist.after_list',
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
    if ($slot === 'ucp.global_navigation') {
        return af_adaptivethemeframework_render_ucp_navigation('global', $context);
    }
    if ($slot === 'ucp.local_navigation') {
        return af_adaptivethemeframework_render_ucp_navigation('local', $context);
    }
    if ($slot === 'modcp.global_navigation') {
        return af_adaptivethemeframework_render_modcp_navigation('global', $context);
    }
    if ($slot === 'modcp.local_navigation') {
        return af_adaptivethemeframework_render_modcp_navigation('local', $context);
    }
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

/** Whether another addon may hand its data DTO to the ATF presentation layer. */
function af_adaptivethemeframework_presentation_is_active(): bool
{
    global $mybb;
    return is_object($mybb)
        && (string)($mybb->settings['af_adaptivethemeframework_enabled'] ?? '0') === '1';
}

/**
 * Render the AAS-owned account-list DTO. AAS remains the route, query and
 * privacy owner; this function deliberately receives no database object.
 */
function af_adaptivethemeframework_render_userlist(array $page): string
{
    $e = static fn($value): string => htmlspecialchars_uni((string)$value);
    $filters = (array)($page['filters'] ?? []); $labels = (array)($filters['labels'] ?? []);
    $selected = static fn(string $actual, string $expected): string => $actual === $expected ? ' selected="selected"' : '';
    $checked = static fn(string $actual, string $expected): string => $actual === $expected ? ' checked="checked"' : '';
    $controls = '<form action="'.$e($filters['action'] ?? 'userlist.php').'" method="get" class="atf-card atf-userlist__filters">'
        .'<h2>'.$e($labels['title'] ?? '').'</h2><div class="atf-form-grid">'
        .'<label>'.$e($labels['username'] ?? '').'<input type="text" class="textbox" name="username" value="'.$e($filters['username'] ?? '').'"></label>'
        .'<label>'.$e($labels['match'] ?? '').'<select name="username_match"><option value="begins"'.$selected((string)($filters['username_match'] ?? ''),'begins').'>'.$e($labels['begins'] ?? '').'</option><option value="contains"'.$selected((string)($filters['username_match'] ?? ''),'contains').'>'.$e($labels['contains'] ?? '').'</option><option value="exact"'.$selected((string)($filters['username_match'] ?? ''),'exact').'>'.$e($labels['exact'] ?? '').'</option></select></label>'
        .'<label>'.$e($labels['sort'] ?? '').'<select name="sort">';
    foreach (['username','regdate','lastvisit','postnum','threadnum','reputation'] as $sortKey) {
        $labelKey = ['username'=>'sort_username','regdate'=>'sort_regdate','lastvisit'=>'sort_last','postnum'=>'sort_posts','threadnum'=>'sort_threads','reputation'=>'sort_reputation'][$sortKey];
        $controls .= '<option value="'.$sortKey.'"'.$selected((string)($filters['sort'] ?? ''),$sortKey).'>'.$e($labels[$labelKey] ?? '').'</option>';
    }
    $controls .= '</select></label><label>'.$e($labels['perpage'] ?? '').'<input type="number" name="perpage" min="1" max="500" value="'.max(1,(int)($filters['perpage'] ?? 20)).'"></label></div>'
        .'<div class="atf-form-actions"><span>'.$e($labels['order'] ?? '').'</span><label><input type="radio" name="order" value="ascending"'.$checked((string)($filters['order'] ?? ''),'ascending').'>ASC</label><label><input type="radio" name="order" value="descending"'.$checked((string)($filters['order'] ?? ''),'descending').'>DESC</label><button type="submit" class="button">'.$e($labels['show'] ?? '').'</button><a class="button" href="userlist.php">'.$e($labels['reset'] ?? '').'</a></div></form>';
    $letterData = (array)($page['letters'] ?? []); $letters = '<nav class="atf-userlist__letters" aria-label="'.$e($letterData['label'] ?? '').'">';
    $common = '&amp;sort='.rawurlencode((string)($letterData['sort'] ?? 'username')).'&amp;order='.rawurlencode((string)($letterData['order'] ?? 'ascending')).'&amp;perpage='.max(1,(int)($letterData['perpage'] ?? 20));
    foreach (range('A','Z') as $letter) $letters .= '<a href="userlist.php?letter='.$letter.$common.$e($letterData['search_query'] ?? '').'">'.$letter.'</a>';
    $letters .= '<a href="userlist.php?letter=-1'.$common.$e($letterData['search_query'] ?? '').'">'.$e($letterData['other'] ?? '').'</a><a href="userlist.php?'.ltrim($common,'&amp;').$e($letterData['search_query'] ?? '').'">'.$e($letterData['reset'] ?? '').'</a></nav>';
    $rows = '';
    foreach ((array)($page['users'] ?? []) as $user) {
        $uid = max(0, (int)($user['uid'] ?? 0));
        if ($uid === 0) continue;
        $context = ['surface'=>'aas_userlist', 'uid'=>$uid, 'user'=>$user];
        $presence = (string)($user['presence']['state'] ?? 'hidden');
        if (!in_array($presence, ['online', 'offline', 'hidden'], true)) $presence = 'hidden';
        $presenceHtml = !empty($user['presence']['can_disclose'])
            ? '<span class="atf-user-card__presence is-'.$presence.'" aria-label="'.$e($user['presence']['label'] ?? $presence).'"></span>' : '';
        $master = (array)($user['master'] ?? []);
        $masterValue = !empty($master['visible'])
            ? '<a href="'.$e($master['profile_url'] ?? '').'">'.$e($master['username_raw'] ?? '').'</a>'
            : '&mdash;';
        // Keep the field structurally stable: no linked account and a
        // privacy-hidden link are intentionally indistinguishable to viewers.
        $masterHtml = '<div><dt>'.$e($page['labels']['master'] ?? '').'</dt><dd>'.$masterValue.'</dd></div>';
        $actions = af_adaptivethemeframework_render_slot('userlist.card.actions', $context);
        $rows .= '<article class="atf-card atf-user-card is-presence-'.$presence.'" data-uid="'.$uid.'">'
            .'<div class="atf-user-card__avatar atf-avatar">'.(string)($user['avatar']['html'] ?? '').$presenceHtml.'</div>'
            .'<div class="atf-user-card__body atf-card__body"><h2 class="atf-user-card__name atf-card__title"><a href="'.$e($user['profile_url'] ?? '').'">'.$e($user['username_raw'] ?? '').'</a></h2>'
            .'<dl class="atf-user-card__meta atf-card__metadata"><div><dt>'.$e($page['labels']['registered'] ?? '').'</dt><dd><time datetime="'.$e($user['registered_at'] ?? '').'" title="'.$e($user['registered_title'] ?? '').'">'.$e($user['registered_text'] ?? '').'</time></dd></div>'
            .'<div><dt>'.$e($page['labels']['active'] ?? '').'</dt><dd><time datetime="'.$e($user['activity_datetime'] ?? '').'" title="'.$e($user['activity_title'] ?? '').'">'.$e($user['activity_text'] ?? '').'</time></dd></div>'
            .'<div><dt>'.$e($page['labels']['posts'] ?? '').'</dt><dd>'.$e($user['post_count'] ?? 0).'</dd></div>'
            .'<div><dt>'.$e($page['labels']['threads'] ?? '').'</dt><dd>'.$e($user['thread_count'] ?? 0).'</dd></div>'
            .$masterHtml.'</dl>'
            .af_adaptivethemeframework_render_slot('userlist.card.meta', $context)
            .($actions !== '' ? '<div class="atf-user-card__actions atf-card__actions">'.$actions.'</div>' : '').'</div></article>';
    }
    if ($rows === '') $rows = '<p class="atf-empty-state">'.$e($page['empty'] ?? '').'</p>';
    $context = ['surface'=>'aas_userlist', 'user_count'=>count((array)($page['users'] ?? []))];
    return '<main class="pun atf-page-shell atf-page atf-userlist"><header class="atf-userlist__header"><h1>'.$e($page['title'] ?? '').'</h1></header>'
        .$controls.$letters
        .af_adaptivethemeframework_render_slot('userlist.before_list', $context)
        .'<section class="atf-user-grid atf-grid atf-list__items" aria-label="'.$e($page['title'] ?? '').'">'.$rows.'</section>'
        .af_adaptivethemeframework_render_slot('userlist.after_list', $context)
        .((string)($page['pagination'] ?? '') !== '' ? '<footer class="atf-list__footer">'.(string)$page['pagination'].'</footer>' : '').'</main>';
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
        // Global breadcrumb presentation while ATF owns the active theme. Core
        // continues to build breadcrumb data/routes; ATF only replaces markup.
        'nav' => AF_ADAPTIVETHEMEFRAMEWORK_BASE . 'templates/nav.html',
        'nav_bit' => AF_ADAPTIVETHEMEFRAMEWORK_BASE . 'templates/nav_bit.html',
        'nav_bit_active' => AF_ADAPTIVETHEMEFRAMEWORK_BASE . 'templates/nav_bit_active.html',
        // MyBB 1.8.40 build_forumbits() selects this template for each visible
        // top-level category and supplies its already permission-filtered children.
        'forumbit_depth1_cat' => AF_ADAPTIVETHEMEFRAMEWORK_BASE . 'templates/forumbit_depth1_cat.html',
        'forumbit_depth2_forum' => AF_ADAPTIVETHEMEFRAMEWORK_BASE . 'templates/forumbit_depth2_forum.html',
        'forumbit_depth2_forum_lastpost' => AF_ADAPTIVETHEMEFRAMEWORK_BASE . 'templates/forumbit_depth2_forum_lastpost.html',
        'forumdisplay' => AF_ADAPTIVETHEMEFRAMEWORK_BASE . 'templates/forumdisplay.html',
        // The stock subforum wrapper is a table. Once depth2 forums are semantic
        // articles, browsers foster-parent those articles outside the table,
        // leaving an empty category bar between subforums and topic cards.
        'forumdisplay_subforums' => AF_ADAPTIVETHEMEFRAMEWORK_BASE . 'templates/forumdisplay_subforums.html',
        // Own the list wrapper as well as its rows: putting semantic <article>
        // cards directly in the stock <tbody> makes browsers foster-parent them
        // outside the list and visually splits the last-post content.
        'forumdisplay_threadlist' => AF_ADAPTIVETHEMEFRAMEWORK_BASE . 'templates/forumdisplay_threadlist.html',
        'forumdisplay_threadlist_rating' => AF_ADAPTIVETHEMEFRAMEWORK_BASE . 'templates/forumdisplay_threadlist_rating.html',
        'forumdisplay_threads_sep' => AF_ADAPTIVETHEMEFRAMEWORK_BASE . 'templates/forumdisplay_threads_sep.html',
        'forumdisplay_thread' => AF_ADAPTIVETHEMEFRAMEWORK_BASE . 'templates/forumdisplay_thread.html',
        'member_profile' => AF_ADAPTIVETHEMEFRAMEWORK_BASE . 'templates/member_profile.html',
        // Task 37 proved these five stock member-list templates participate in
        // layout. Nested avatar/group/star/order templates remain core-owned.
        'memberlist' => AF_ADAPTIVETHEMEFRAMEWORK_BASE . 'templates/memberlist.html',
        'memberlist_user' => AF_ADAPTIVETHEMEFRAMEWORK_BASE . 'templates/memberlist_user.html',
        'memberlist_search' => AF_ADAPTIVETHEMEFRAMEWORK_BASE . 'templates/memberlist_search.html',
        'memberlist_error' => AF_ADAPTIVETHEMEFRAMEWORK_BASE . 'templates/memberlist_error.html',
        'memberlist_referrals_bit' => AF_ADAPTIVETHEMEFRAMEWORK_BASE . 'templates/memberlist_referrals_bit.html',
        // Task 44 owns the proven full-document ModCP roots. Later staged
        // tasks may add only the child presentation required by the surface
        // being migrated; unrelated forms/fragments remain core-owned.
        'modcp' => AF_ADAPTIVETHEMEFRAMEWORK_BASE . 'templates/modcp.html',
        'modcp_reports' => AF_ADAPTIVETHEMEFRAMEWORK_BASE . 'templates/modcp_reports.html',
        'modcp_reports_allreports' => AF_ADAPTIVETHEMEFRAMEWORK_BASE . 'templates/modcp_reports_allreports.html',
        'modcp_modlogs' => AF_ADAPTIVETHEMEFRAMEWORK_BASE . 'templates/modcp_modlogs.html',
        'modcp_announcements' => AF_ADAPTIVETHEMEFRAMEWORK_BASE . 'templates/modcp_announcements.html',
        'modcp_announcements_new' => AF_ADAPTIVETHEMEFRAMEWORK_BASE . 'templates/modcp_announcements_new.html',
        'modcp_announcements_edit' => AF_ADAPTIVETHEMEFRAMEWORK_BASE . 'templates/modcp_announcements_edit.html',
        'modcp_announcements_delete' => AF_ADAPTIVETHEMEFRAMEWORK_BASE . 'templates/modcp_announcements_delete.html',
        'modcp_modqueue_threads' => AF_ADAPTIVETHEMEFRAMEWORK_BASE . 'templates/modcp_modqueue_threads.html',
        'modcp_modqueue_posts' => AF_ADAPTIVETHEMEFRAMEWORK_BASE . 'templates/modcp_modqueue_posts.html',
        'modcp_modqueue_attachments' => AF_ADAPTIVETHEMEFRAMEWORK_BASE . 'templates/modcp_modqueue_attachments.html',
        'modcp_modqueue_empty' => AF_ADAPTIVETHEMEFRAMEWORK_BASE . 'templates/modcp_modqueue_empty.html',
        'modcp_finduser' => AF_ADAPTIVETHEMEFRAMEWORK_BASE . 'templates/modcp_finduser.html',
        'modcp_editprofile' => AF_ADAPTIVETHEMEFRAMEWORK_BASE . 'templates/modcp_editprofile.html',
        'modcp_warninglogs' => AF_ADAPTIVETHEMEFRAMEWORK_BASE . 'templates/modcp_warninglogs.html',
        'modcp_ipsearch' => AF_ADAPTIVETHEMEFRAMEWORK_BASE . 'templates/modcp_ipsearch.html',
        'modcp_banning' => AF_ADAPTIVETHEMEFRAMEWORK_BASE . 'templates/modcp_banning.html',
        'modcp_banuser' => AF_ADAPTIVETHEMEFRAMEWORK_BASE . 'templates/modcp_banuser.html',

        // Task 45 migrates only the child presentation that is exclusive to
        // Overview, Moderator Log and All Reports. Shared ban-row fragments
        // remain core-owned until the dedicated bans task.
        'modcp_awaitingmoderation' => AF_ADAPTIVETHEMEFRAMEWORK_BASE . 'templates/modcp_awaitingmoderation.html',
        'modcp_awaitingthreads' => AF_ADAPTIVETHEMEFRAMEWORK_BASE . 'templates/modcp_awaitingthreads.html',
        'modcp_awaitingposts' => AF_ADAPTIVETHEMEFRAMEWORK_BASE . 'templates/modcp_awaitingposts.html',
        'modcp_awaitingattachments' => AF_ADAPTIVETHEMEFRAMEWORK_BASE . 'templates/modcp_awaitingattachments.html',
        'modcp_awaitingmoderation_none' => AF_ADAPTIVETHEMEFRAMEWORK_BASE . 'templates/modcp_awaitingmoderation_none.html',
        'modcp_latestfivemodactions' => AF_ADAPTIVETHEMEFRAMEWORK_BASE . 'templates/modcp_latestfivemodactions.html',
        'modcp_modlogs_result' => AF_ADAPTIVETHEMEFRAMEWORK_BASE . 'templates/modcp_modlogs_result.html',
        'modcp_modlogs_noresults' => AF_ADAPTIVETHEMEFRAMEWORK_BASE . 'templates/modcp_modlogs_noresults.html',
        'modcp_modlogs_nologs' => AF_ADAPTIVETHEMEFRAMEWORK_BASE . 'templates/modcp_modlogs_nologs.html',
        'modcp_modlogs_multipage' => AF_ADAPTIVETHEMEFRAMEWORK_BASE . 'templates/modcp_modlogs_multipage.html',
        'modcp_reports_allreport' => AF_ADAPTIVETHEMEFRAMEWORK_BASE . 'templates/modcp_reports_allreport.html',
        'modcp_reports_allnoreports' => AF_ADAPTIVETHEMEFRAMEWORK_BASE . 'templates/modcp_reports_allnoreports.html',

        // Task 46: only the child presentation required by Open Reports and
        // the three moderation queues. Core continues to own queries, scope,
        // processing, radio values, report cookies and redirects.
        'modcp_reports_report' => AF_ADAPTIVETHEMEFRAMEWORK_BASE . 'templates/modcp_reports_report.html',
        'modcp_reports_noreports' => AF_ADAPTIVETHEMEFRAMEWORK_BASE . 'templates/modcp_reports_noreports.html',
        'modcp_reports_selectall' => AF_ADAPTIVETHEMEFRAMEWORK_BASE . 'templates/modcp_reports_selectall.html',
        'modcp_modqueue_threads_thread' => AF_ADAPTIVETHEMEFRAMEWORK_BASE . 'templates/modcp_modqueue_threads_thread.html',
        'modcp_modqueue_threads_empty' => AF_ADAPTIVETHEMEFRAMEWORK_BASE . 'templates/modcp_modqueue_threads_empty.html',
        'modcp_modqueue_posts_post' => AF_ADAPTIVETHEMEFRAMEWORK_BASE . 'templates/modcp_modqueue_posts_post.html',
        'modcp_modqueue_posts_empty' => AF_ADAPTIVETHEMEFRAMEWORK_BASE . 'templates/modcp_modqueue_posts_empty.html',
        'modcp_modqueue_attachments_attachment' => AF_ADAPTIVETHEMEFRAMEWORK_BASE . 'templates/modcp_modqueue_attachments_attachment.html',
        'modcp_modqueue_attachments_empty' => AF_ADAPTIVETHEMEFRAMEWORK_BASE . 'templates/modcp_modqueue_attachments_empty.html',
        'modcp_modqueue_masscontrols' => AF_ADAPTIVETHEMEFRAMEWORK_BASE . 'templates/modcp_modqueue_masscontrols.html',

        // Task 47: exclusive read/search presentation for IP Search, Warning
        // Log and Find User. Shared edit-profile fragments stay untouched for
        // Task 48; MyBB continues to own queries, filters and authorization.
        'modcp_ipsearch_results' => AF_ADAPTIVETHEMEFRAMEWORK_BASE . 'templates/modcp_ipsearch_results.html',
        'modcp_ipsearch_result' => AF_ADAPTIVETHEMEFRAMEWORK_BASE . 'templates/modcp_ipsearch_result.html',
        'modcp_ipsearch_noresults' => AF_ADAPTIVETHEMEFRAMEWORK_BASE . 'templates/modcp_ipsearch_noresults.html',
        'modcp_ipsearch_misc_info' => AF_ADAPTIVETHEMEFRAMEWORK_BASE . 'templates/modcp_ipsearch_misc_info.html',
        'modcp_warninglogs_warning' => AF_ADAPTIVETHEMEFRAMEWORK_BASE . 'templates/modcp_warninglogs_warning.html',
        'modcp_warninglogs_warning_revoked' => AF_ADAPTIVETHEMEFRAMEWORK_BASE . 'templates/modcp_warninglogs_warning_revoked.html',
        'modcp_warninglogs_nologs' => AF_ADAPTIVETHEMEFRAMEWORK_BASE . 'templates/modcp_warninglogs_nologs.html',
        'modcp_finduser_user' => AF_ADAPTIVETHEMEFRAMEWORK_BASE . 'templates/modcp_finduser_user.html',
        'modcp_finduser_noresults' => AF_ADAPTIVETHEMEFRAMEWORK_BASE . 'templates/modcp_finduser_noresults.html',

        // Task 48: only ModCP-exclusive edit-profile fragments are added to
        // ownership. Shared usercp_profile_* templates remain under their
        // existing UCP/APF ownership boundary and are reused as rendered data.
        'modcp_editprofile_signature' => AF_ADAPTIVETHEMEFRAMEWORK_BASE . 'templates/modcp_editprofile_signature.html',
        'modcp_editprofile_suspensions_info' => AF_ADAPTIVETHEMEFRAMEWORK_BASE . 'templates/modcp_editprofile_suspensions_info.html',

        // Task 49: ban presentation. The shared modcp_banning_ban child is
        // intentionally owned now that both the dedicated ban list and the
        // Task 45 overview consume the same semantic card shape.
        'modcp_banning_ban' => AF_ADAPTIVETHEMEFRAMEWORK_BASE . 'templates/modcp_banning_ban.html',
        'modcp_banning_edit' => AF_ADAPTIVETHEMEFRAMEWORK_BASE . 'templates/modcp_banning_edit.html',
        'modcp_banning_remaining' => AF_ADAPTIVETHEMEFRAMEWORK_BASE . 'templates/modcp_banning_remaining.html',
        'modcp_banning_nobanned' => AF_ADAPTIVETHEMEFRAMEWORK_BASE . 'templates/modcp_banning_nobanned.html',
        'modcp_nobanned' => AF_ADAPTIVETHEMEFRAMEWORK_BASE . 'templates/modcp_nobanned.html',
        'modcp_banuser_addusername' => AF_ADAPTIVETHEMEFRAMEWORK_BASE . 'templates/modcp_banuser_addusername.html',
        'modcp_banuser_editusername' => AF_ADAPTIVETHEMEFRAMEWORK_BASE . 'templates/modcp_banuser_editusername.html',
        'modcp_banuser_bangroups' => AF_ADAPTIVETHEMEFRAMEWORK_BASE . 'templates/modcp_banuser_bangroups.html',
        'modcp_banuser_lift' => AF_ADAPTIVETHEMEFRAMEWORK_BASE . 'templates/modcp_banuser_lift.html',

        // Task 50: ModCP-exclusive announcement presentation. Public
        // announcement_* templates remain core-owned; ATF does not alter
        // announcement storage, parsing, permissions or mutation routes.
        'modcp_announcements_global' => AF_ADAPTIVETHEMEFRAMEWORK_BASE . 'templates/modcp_announcements_global.html',
        'modcp_announcements_forum' => AF_ADAPTIVETHEMEFRAMEWORK_BASE . 'templates/modcp_announcements_forum.html',
        'modcp_announcements_forum_nomod' => AF_ADAPTIVETHEMEFRAMEWORK_BASE . 'templates/modcp_announcements_forum_nomod.html',
        'modcp_announcements_announcement' => AF_ADAPTIVETHEMEFRAMEWORK_BASE . 'templates/modcp_announcements_announcement.html',
        'modcp_announcements_announcement_global' => AF_ADAPTIVETHEMEFRAMEWORK_BASE . 'templates/modcp_announcements_announcement_global.html',
        'modcp_no_announcements_global' => AF_ADAPTIVETHEMEFRAMEWORK_BASE . 'templates/modcp_no_announcements_global.html',
        'modcp_no_announcements_forum' => AF_ADAPTIVETHEMEFRAMEWORK_BASE . 'templates/modcp_no_announcements_forum.html',
        'modcp_announcements_allowhtml' => AF_ADAPTIVETHEMEFRAMEWORK_BASE . 'templates/modcp_announcements_allowhtml.html',

        'usercp' => AF_ADAPTIVETHEMEFRAMEWORK_BASE . 'templates/usercp.html',
        'usercp_currentavatar' => AF_ADAPTIVETHEMEFRAMEWORK_BASE . 'templates/usercp_currentavatar.html',
        'usercp_notepad' => AF_ADAPTIVETHEMEFRAMEWORK_BASE . 'templates/usercp_notepad.html',
        'usercp_latest_threads' => AF_ADAPTIVETHEMEFRAMEWORK_BASE . 'templates/usercp_latest_threads.html',
        'usercp_latest_threads_threads' => AF_ADAPTIVETHEMEFRAMEWORK_BASE . 'templates/usercp_latest_threads_threads.html',
        'usercp_latest_subscribed' => AF_ADAPTIVETHEMEFRAMEWORK_BASE . 'templates/usercp_latest_subscribed.html',
        'usercp_latest_subscribed_threads' => AF_ADAPTIVETHEMEFRAMEWORK_BASE . 'templates/usercp_latest_subscribed_threads.html',
        'usercp_warnings' => AF_ADAPTIVETHEMEFRAMEWORK_BASE . 'templates/usercp_warnings.html',
        'usercp_warnings_warning' => AF_ADAPTIVETHEMEFRAMEWORK_BASE . 'templates/usercp_warnings_warning.html',
        'usercp_warnings_warning_post' => AF_ADAPTIVETHEMEFRAMEWORK_BASE . 'templates/usercp_warnings_warning_post.html',
        'usercp_profile' => AF_ADAPTIVETHEMEFRAMEWORK_BASE . 'templates/usercp_profile.html',
        'usercp_profile_away' => AF_ADAPTIVETHEMEFRAMEWORK_BASE . 'templates/usercp_profile_away.html',
        'usercp_profile_website' => AF_ADAPTIVETHEMEFRAMEWORK_BASE . 'templates/usercp_profile_website.html',
        'usercp_profile_customtitle' => AF_ADAPTIVETHEMEFRAMEWORK_BASE . 'templates/usercp_profile_customtitle.html',
        'usercp_profile_customtitle_currentcustom' => AF_ADAPTIVETHEMEFRAMEWORK_BASE . 'templates/usercp_profile_customtitle_currentcustom.html',
        'usercp_profile_customtitle_reverttitle' => AF_ADAPTIVETHEMEFRAMEWORK_BASE . 'templates/usercp_profile_customtitle_reverttitle.html',
        'usercp_profile_profilefields' => AF_ADAPTIVETHEMEFRAMEWORK_BASE . 'templates/usercp_profile_profilefields.html',
        'usercp_avatar' => AF_ADAPTIVETHEMEFRAMEWORK_BASE . 'templates/usercp_avatar.html',
        'usercp_avatar_current' => AF_ADAPTIVETHEMEFRAMEWORK_BASE . 'templates/usercp_avatar_current.html',
        'usercp_avatar_upload' => AF_ADAPTIVETHEMEFRAMEWORK_BASE . 'templates/usercp_avatar_upload.html',
        'usercp_avatar_remote' => AF_ADAPTIVETHEMEFRAMEWORK_BASE . 'templates/usercp_avatar_remote.html',
        'usercp_avatar_remove' => AF_ADAPTIVETHEMEFRAMEWORK_BASE . 'templates/usercp_avatar_remove.html',
        'usercp_avatar_auto_resize_auto' => AF_ADAPTIVETHEMEFRAMEWORK_BASE . 'templates/usercp_avatar_auto_resize_auto.html',
        'usercp_avatar_auto_resize_user' => AF_ADAPTIVETHEMEFRAMEWORK_BASE . 'templates/usercp_avatar_auto_resize_user.html',
        'usercp_editsig' => AF_ADAPTIVETHEMEFRAMEWORK_BASE . 'templates/usercp_editsig.html',
        'usercp_editsig_current' => AF_ADAPTIVETHEMEFRAMEWORK_BASE . 'templates/usercp_editsig_current.html',
        'usercp_editsig_preview' => AF_ADAPTIVETHEMEFRAMEWORK_BASE . 'templates/usercp_editsig_preview.html',
        'usercp_editsig_suspended' => AF_ADAPTIVETHEMEFRAMEWORK_BASE . 'templates/usercp_editsig_suspended.html',
        'usercp_options' => AF_ADAPTIVETHEMEFRAMEWORK_BASE . 'templates/usercp_options.html',
        'usercp_options_invisible' => AF_ADAPTIVETHEMEFRAMEWORK_BASE . 'templates/usercp_options_invisible.html',
        'usercp_options_date_format' => AF_ADAPTIVETHEMEFRAMEWORK_BASE . 'templates/usercp_options_date_format.html',
        'usercp_options_language' => AF_ADAPTIVETHEMEFRAMEWORK_BASE . 'templates/usercp_options_language.html',
        'usercp_options_language_option' => AF_ADAPTIVETHEMEFRAMEWORK_BASE . 'templates/usercp_options_language_option.html',
        'usercp_options_pms' => AF_ADAPTIVETHEMEFRAMEWORK_BASE . 'templates/usercp_options_pms.html',
        'usercp_options_pms_from_buddys' => AF_ADAPTIVETHEMEFRAMEWORK_BASE . 'templates/usercp_options_pms_from_buddys.html',
        'usercp_options_pppselect' => AF_ADAPTIVETHEMEFRAMEWORK_BASE . 'templates/usercp_options_pppselect.html',
        'usercp_options_pppselect_option' => AF_ADAPTIVETHEMEFRAMEWORK_BASE . 'templates/usercp_options_pppselect_option.html',
        'usercp_options_quick_reply' => AF_ADAPTIVETHEMEFRAMEWORK_BASE . 'templates/usercp_options_quick_reply.html',
        'usercp_options_style' => AF_ADAPTIVETHEMEFRAMEWORK_BASE . 'templates/usercp_options_style.html',
        'usercp_options_time_format' => AF_ADAPTIVETHEMEFRAMEWORK_BASE . 'templates/usercp_options_time_format.html',
        'usercp_options_timezone' => AF_ADAPTIVETHEMEFRAMEWORK_BASE . 'templates/usercp_options_timezone.html',
        'usercp_options_timezone_option' => AF_ADAPTIVETHEMEFRAMEWORK_BASE . 'templates/usercp_options_timezone_option.html',
        'usercp_options_tppselect' => AF_ADAPTIVETHEMEFRAMEWORK_BASE . 'templates/usercp_options_tppselect.html',
        'usercp_options_tppselect_option' => AF_ADAPTIVETHEMEFRAMEWORK_BASE . 'templates/usercp_options_tppselect_option.html',
        'usercp_themeselector' => AF_ADAPTIVETHEMEFRAMEWORK_BASE . 'templates/usercp_themeselector.html',
        'usercp_themeselector_option' => AF_ADAPTIVETHEMEFRAMEWORK_BASE . 'templates/usercp_themeselector_option.html',
        'usercp_password' => AF_ADAPTIVETHEMEFRAMEWORK_BASE . 'templates/usercp_password.html',
        'usercp_email' => AF_ADAPTIVETHEMEFRAMEWORK_BASE . 'templates/usercp_email.html',
        'usercp_changename' => AF_ADAPTIVETHEMEFRAMEWORK_BASE . 'templates/usercp_changename.html',
        'usercp_editlists' => AF_ADAPTIVETHEMEFRAMEWORK_BASE . 'templates/usercp_editlists.html',
        'usercp_editlists_user' => AF_ADAPTIVETHEMEFRAMEWORK_BASE . 'templates/usercp_editlists_user.html',
        'usercp_editlists_no_buddies' => AF_ADAPTIVETHEMEFRAMEWORK_BASE . 'templates/usercp_editlists_no_buddies.html',
        'usercp_editlists_no_ignored' => AF_ADAPTIVETHEMEFRAMEWORK_BASE . 'templates/usercp_editlists_no_ignored.html',
        'usercp_editlists_received_requests' => AF_ADAPTIVETHEMEFRAMEWORK_BASE . 'templates/usercp_editlists_received_requests.html',
        'usercp_editlists_received_request' => AF_ADAPTIVETHEMEFRAMEWORK_BASE . 'templates/usercp_editlists_received_request.html',
        'usercp_editlists_sent_requests' => AF_ADAPTIVETHEMEFRAMEWORK_BASE . 'templates/usercp_editlists_sent_requests.html',
        'usercp_editlists_sent_request' => AF_ADAPTIVETHEMEFRAMEWORK_BASE . 'templates/usercp_editlists_sent_request.html',
        'usercp_editlists_no_requests' => AF_ADAPTIVETHEMEFRAMEWORK_BASE . 'templates/usercp_editlists_no_requests.html',
        'usercp_usergroups' => AF_ADAPTIVETHEMEFRAMEWORK_BASE . 'templates/usercp_usergroups.html',
        'usercp_usergroups_joingroup' => AF_ADAPTIVETHEMEFRAMEWORK_BASE . 'templates/usercp_usergroups_joingroup.html',
        'usercp_usergroups_leader' => AF_ADAPTIVETHEMEFRAMEWORK_BASE . 'templates/usercp_usergroups_leader.html',
        'usercp_usergroups_leader_usergroup' => AF_ADAPTIVETHEMEFRAMEWORK_BASE . 'templates/usercp_usergroups_leader_usergroup.html',
        'usercp_usergroups_leader_usergroup_memberlist' => AF_ADAPTIVETHEMEFRAMEWORK_BASE . 'templates/usercp_usergroups_leader_usergroup_memberlist.html',
        'usercp_usergroups_leader_usergroup_moderaterequests' => AF_ADAPTIVETHEMEFRAMEWORK_BASE . 'templates/usercp_usergroups_leader_usergroup_moderaterequests.html',
        'usercp_usergroups_memberof' => AF_ADAPTIVETHEMEFRAMEWORK_BASE . 'templates/usercp_usergroups_memberof.html',
        'usercp_usergroups_memberof_usergroup' => AF_ADAPTIVETHEMEFRAMEWORK_BASE . 'templates/usercp_usergroups_memberof_usergroup.html',
        'usercp_usergroups_memberof_usergroup_description' => AF_ADAPTIVETHEMEFRAMEWORK_BASE . 'templates/usercp_usergroups_memberof_usergroup_description.html',
        'usercp_usergroups_memberof_usergroup_display' => AF_ADAPTIVETHEMEFRAMEWORK_BASE . 'templates/usercp_usergroups_memberof_usergroup_display.html',
        'usercp_usergroups_memberof_usergroup_leave' => AF_ADAPTIVETHEMEFRAMEWORK_BASE . 'templates/usercp_usergroups_memberof_usergroup_leave.html',
        'usercp_usergroups_memberof_usergroup_leaveleader' => AF_ADAPTIVETHEMEFRAMEWORK_BASE . 'templates/usercp_usergroups_memberof_usergroup_leaveleader.html',
        'usercp_usergroups_memberof_usergroup_leaveother' => AF_ADAPTIVETHEMEFRAMEWORK_BASE . 'templates/usercp_usergroups_memberof_usergroup_leaveother.html',
        'usercp_usergroups_memberof_usergroup_leaveprimary' => AF_ADAPTIVETHEMEFRAMEWORK_BASE . 'templates/usercp_usergroups_memberof_usergroup_leaveprimary.html',
        'usercp_usergroups_memberof_usergroup_setdisplay' => AF_ADAPTIVETHEMEFRAMEWORK_BASE . 'templates/usercp_usergroups_memberof_usergroup_setdisplay.html',
        'usercp_usergroups_joinable' => AF_ADAPTIVETHEMEFRAMEWORK_BASE . 'templates/usercp_usergroups_joinable.html',
        'usercp_usergroups_joinable_usergroup' => AF_ADAPTIVETHEMEFRAMEWORK_BASE . 'templates/usercp_usergroups_joinable_usergroup.html',
        'usercp_usergroups_joinable_usergroup_description' => AF_ADAPTIVETHEMEFRAMEWORK_BASE . 'templates/usercp_usergroups_joinable_usergroup_description.html',
        'usercp_usergroups_joinable_usergroup_join' => AF_ADAPTIVETHEMEFRAMEWORK_BASE . 'templates/usercp_usergroups_joinable_usergroup_join.html',
        'usercp_subscriptions' => AF_ADAPTIVETHEMEFRAMEWORK_BASE . 'templates/usercp_subscriptions.html',
        'usercp_subscriptions_thread' => AF_ADAPTIVETHEMEFRAMEWORK_BASE . 'templates/usercp_subscriptions_thread.html',
        'usercp_subscriptions_thread_icon' => AF_ADAPTIVETHEMEFRAMEWORK_BASE . 'templates/usercp_subscriptions_thread_icon.html',
        'usercp_subscriptions_remove' => AF_ADAPTIVETHEMEFRAMEWORK_BASE . 'templates/usercp_subscriptions_remove.html',
        'usercp_subscriptions_none' => AF_ADAPTIVETHEMEFRAMEWORK_BASE . 'templates/usercp_subscriptions_none.html',
        'usercp_forumsubscriptions' => AF_ADAPTIVETHEMEFRAMEWORK_BASE . 'templates/usercp_forumsubscriptions.html',
        'usercp_forumsubscriptions_forum' => AF_ADAPTIVETHEMEFRAMEWORK_BASE . 'templates/usercp_forumsubscriptions_forum.html',
        'usercp_forumsubscriptions_none' => AF_ADAPTIVETHEMEFRAMEWORK_BASE . 'templates/usercp_forumsubscriptions_none.html',
        'usercp_addsubscription_thread' => AF_ADAPTIVETHEMEFRAMEWORK_BASE . 'templates/usercp_addsubscription_thread.html',
        'usercp_removesubscription_thread' => AF_ADAPTIVETHEMEFRAMEWORK_BASE . 'templates/usercp_removesubscription_thread.html',
        'usercp_removesubscription_forum' => AF_ADAPTIVETHEMEFRAMEWORK_BASE . 'templates/usercp_removesubscription_forum.html',
        'usercp_drafts' => AF_ADAPTIVETHEMEFRAMEWORK_BASE . 'templates/usercp_drafts.html',
        'usercp_drafts_draft' => AF_ADAPTIVETHEMEFRAMEWORK_BASE . 'templates/usercp_drafts_draft.html',
        'usercp_drafts_draft_forum' => AF_ADAPTIVETHEMEFRAMEWORK_BASE . 'templates/usercp_drafts_draft_forum.html',
        'usercp_drafts_draft_thread' => AF_ADAPTIVETHEMEFRAMEWORK_BASE . 'templates/usercp_drafts_draft_thread.html',
        'usercp_drafts_none' => AF_ADAPTIVETHEMEFRAMEWORK_BASE . 'templates/usercp_drafts_none.html',
        'usercp_attachments' => AF_ADAPTIVETHEMEFRAMEWORK_BASE . 'templates/usercp_attachments.html',
        'usercp_attachments_attachment' => AF_ADAPTIVETHEMEFRAMEWORK_BASE . 'templates/usercp_attachments_attachment.html',
        'usercp_attachments_none' => AF_ADAPTIVETHEMEFRAMEWORK_BASE . 'templates/usercp_attachments_none.html',
        'delete_attachments_button' => AF_ADAPTIVETHEMEFRAMEWORK_BASE . 'templates/delete_attachments_button.html',
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
        'private_read' => AF_ADAPTIVETHEMEFRAMEWORK_BASE . 'templates/private_read.html',
        'private_read_action' => AF_ADAPTIVETHEMEFRAMEWORK_BASE . 'templates/private_read_action.html',
        'private_read_to' => AF_ADAPTIVETHEMEFRAMEWORK_BASE . 'templates/private_read_to.html',
        'private_read_bcc' => AF_ADAPTIVETHEMEFRAMEWORK_BASE . 'templates/private_read_bcc.html',
        'private_quickreply' => AF_ADAPTIVETHEMEFRAMEWORK_BASE . 'templates/private_quickreply.html',
        'private_send' => AF_ADAPTIVETHEMEFRAMEWORK_BASE . 'templates/private_send.html',
        'private_send_autocomplete' => AF_ADAPTIVETHEMEFRAMEWORK_BASE . 'templates/private_send_autocomplete.html',
        'private_send_buddyselect' => AF_ADAPTIVETHEMEFRAMEWORK_BASE . 'templates/private_send_buddyselect.html',
        'private_send_tracking' => AF_ADAPTIVETHEMEFRAMEWORK_BASE . 'templates/private_send_tracking.html',
        'private_tracking' => AF_ADAPTIVETHEMEFRAMEWORK_BASE . 'templates/private_tracking.html',
        'private_tracking_nomessage' => AF_ADAPTIVETHEMEFRAMEWORK_BASE . 'templates/private_tracking_nomessage.html',
        'private_tracking_readmessage' => AF_ADAPTIVETHEMEFRAMEWORK_BASE . 'templates/private_tracking_readmessage.html',
        'private_tracking_unreadmessage' => AF_ADAPTIVETHEMEFRAMEWORK_BASE . 'templates/private_tracking_unreadmessage.html',
        'private_tracking_readmessage_stop' => AF_ADAPTIVETHEMEFRAMEWORK_BASE . 'templates/private_tracking_readmessage_stop.html',
        'private_tracking_unreadmessage_stop' => AF_ADAPTIVETHEMEFRAMEWORK_BASE . 'templates/private_tracking_unreadmessage_stop.html',
        'private_folders' => AF_ADAPTIVETHEMEFRAMEWORK_BASE . 'templates/private_folders.html',
        'private_folders_folder' => AF_ADAPTIVETHEMEFRAMEWORK_BASE . 'templates/private_folders_folder.html',
        'private_folders_folder_unremovable' => AF_ADAPTIVETHEMEFRAMEWORK_BASE . 'templates/private_folders_folder_unremovable.html',
        'private_empty' => AF_ADAPTIVETHEMEFRAMEWORK_BASE . 'templates/private_empty.html',
        'private_empty_folder' => AF_ADAPTIVETHEMEFRAMEWORK_BASE . 'templates/private_empty_folder.html',
        'private_archive' => AF_ADAPTIVETHEMEFRAMEWORK_BASE . 'templates/private_archive.html',
        'private_archive_folders' => AF_ADAPTIVETHEMEFRAMEWORK_BASE . 'templates/private_archive_folders.html',
        'private_archive_folders_folder' => AF_ADAPTIVETHEMEFRAMEWORK_BASE . 'templates/private_archive_folders_folder.html',
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
        return af_adaptivethemeframework_ensure_ownership_schema()
            && af_adaptivethemeframework_ensure_preferences_schema();
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
