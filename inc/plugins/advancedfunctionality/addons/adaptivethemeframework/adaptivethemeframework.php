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
define('AF_ADAPTIVETHEMEFRAMEWORK_VERSION', '0.4.0');

function af_adaptivethemeframework_init(): void
{
    global $plugins;
    if (is_object($plugins) && method_exists($plugins, 'add_hook')) {
        $plugins->add_hook('pre_output_page', 'af_adaptivethemeframework_mark_page', 100);
    }
}

/**
 * Add the single activation boundary used by every ATF design-system rule.
 *
 * This runtime marker, rather than a template edit, keeps the current MyBB
 * templates untouched and makes a retained/cached stylesheet inert whenever
 * the addon is disabled.
 */
function af_adaptivethemeframework_mark_page(string &$page): void
{
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
                $replacement = 'class=' . $classMatch[1][0]
                    . implode(' ', array_filter($classes))
                    . $classMatch[1][0];
                $attributes = substr_replace(
                    $attributes,
                    $replacement,
                    (int)$classMatch[0][1],
                    strlen($classMatch[0][0])
                );
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

function af_adaptivethemeframework_install(): bool
{
    return af_adaptivethemeframework_acquire_index();
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
    return af_adaptivethemeframework_acquire_index();
}

function af_adaptivethemeframework_deactivate(): bool
{
    return af_adaptivethemeframework_release_index();
}

function af_adaptivethemeframework_uninstall(): bool
{
    // Keep the ledger: conflicts contain the only durable copy of both versions.
    return af_adaptivethemeframework_release_index();
}

function af_adaptivethemeframework_index_seed(): string
{
    $seed = @file_get_contents(AF_ADAPTIVETHEMEFRAMEWORK_BASE . 'templates/index.html');
    return is_string($seed) ? $seed : '';
}

function af_adaptivethemeframework_checksum(string $content): string
{
    return hash('sha256', $content);
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
    // MyBB's addon controller can display this exception instead of an unlabelled HTTP 500.
    throw new RuntimeException('Adaptive Theme Framework activation failed at ' . $stage . ': ' . $error->getMessage(), 0, $error);
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
            if (!$db->field_exists($column, $table)) {
                $db->add_column($table, $column, $definition);
            }
        }
        $indexes = ['template_lease' => 'UNIQUE KEY template_lease (template_sid, template_name)', 'ownership_state' => 'KEY ownership_state (ownership_state)', 'template_tid' => 'KEY template_tid (template_tid)'];
        foreach ($indexes as $name => $definition) {
            if (method_exists($db, 'index_exists') && !$db->index_exists($table, $name)) {
                $db->write_query('ALTER TABLE ' . TABLE_PREFIX . $table . ' ADD ' . $definition);
            }
        }
    }
    af_adaptivethemeframework_activation_stage('validate_schema');
    if (!$db->table_exists($table)) {
        return false;
    }
    foreach (array_keys(af_adaptivethemeframework_ownership_columns()) as $column) {
        if (!$db->field_exists($column, $table)) {
            return false;
        }
    }
    return true;
}

/** Acquire a reversible, per-template-set lease for index. Master sid=-2 is read only. */
function af_adaptivethemeframework_acquire_index(): bool
{
    global $db;
    if (!is_object($db)) {
        return true;
    }
    try {
        if (!af_adaptivethemeframework_ensure_ownership_schema()) {
            throw new RuntimeException('ownership schema validation failed');
        }
        af_adaptivethemeframework_activation_stage('validate_index_seed');
        $seed = af_adaptivethemeframework_index_seed();
        if ($seed === '') {
            throw new RuntimeException('index seed is empty or unreadable');
        }
        $seedChecksum = af_adaptivethemeframework_checksum($seed);
        $now = defined('TIME_NOW') ? TIME_NOW : time();
        af_adaptivethemeframework_activation_stage('load_master_index');
        $master = $db->fetch_array($db->simple_select('templates', '*', "title='index' AND sid='-2'", ['limit' => 1]));
        if (!$master) {
            throw new RuntimeException('master index (sid=-2) was not found');
        }
        af_adaptivethemeframework_activation_stage('load_templatesets');
        $sets = $db->simple_select('templatesets', 'sid', 'sid>0', ['order_by' => 'sid']);
        while ($set = $db->fetch_array($sets)) {
            $sid = (int)$set['sid'];
            af_adaptivethemeframework_activation_stage('inspect_index[sid=' . $sid . ']');
            $live = $db->fetch_array($db->simple_select('templates', '*', "title='index' AND sid='{$sid}'", ['limit' => 1]));
            $exists = !empty($live);
            $current = (string)($exists ? $live['template'] : $master['template']);
            af_adaptivethemeframework_activation_stage('load_lease[sid=' . $sid . ']');
            $lease = $db->fetch_array($db->simple_select(AF_ADAPTIVETHEMEFRAMEWORK_TEMPLATE_TABLE_NAME, '*', "template_sid='{$sid}' AND template_name='index'", ['limit' => 1]));

            if ($lease && $lease['ownership_state'] !== 'restored') {
                if ($lease['ownership_state'] !== 'owned' || !hash_equals((string)$lease['atf_installed_checksum'], af_adaptivethemeframework_checksum($current))) {
                    af_adaptivethemeframework_activation_stage('update_lease[sid=' . $sid . ']');
                    $db->update_query(AF_ADAPTIVETHEMEFRAMEWORK_TEMPLATE_TABLE_NAME, ['ownership_state' => 'manual_override', 'current_content' => $current, 'updated_at' => $now], "id='".(int)$lease['id']."'");
                    throw new RuntimeException('index ownership cannot be proven for template set ' . $sid);
                }
                if (hash_equals((string)$lease['atf_seed_checksum'], $seedChecksum)) {
                    continue;
                }
                // Persist the intended checksum before changing the live template.
                af_adaptivethemeframework_activation_stage('update_lease[sid=' . $sid . ']');
                $db->update_query(AF_ADAPTIVETHEMEFRAMEWORK_TEMPLATE_TABLE_NAME, ['atf_seed_content' => $seed, 'atf_seed_checksum' => $seedChecksum, 'atf_installed_checksum' => $seedChecksum, 'atf_version' => AF_ADAPTIVETHEMEFRAMEWORK_VERSION, 'updated_at' => $now], "id='".(int)$lease['id']."'");
            } else {
                $record = ['template_name' => 'index', 'template_sid' => $sid, 'template_tid' => $exists ? (int)$live['tid'] : 0, 'previous_exists' => $exists ? 1 : 0, 'previous_content' => $current, 'previous_checksum' => af_adaptivethemeframework_checksum($current), 'previous_dateline' => (int)($exists ? ($live['dateline'] ?? 0) : ($master['dateline'] ?? 0)), 'atf_seed_content' => $seed, 'atf_seed_checksum' => $seedChecksum, 'atf_installed_checksum' => $seedChecksum, 'atf_version' => AF_ADAPTIVETHEMEFRAMEWORK_VERSION, 'ownership_state' => 'owned', 'current_content' => '', 'created_at' => $now, 'updated_at' => $now, 'restored_at' => 0];
                af_adaptivethemeframework_activation_stage(($lease ? 'update_lease' : 'create_lease') . '[sid=' . $sid . ']');
                if ($lease) {
                    $db->update_query(AF_ADAPTIVETHEMEFRAMEWORK_TEMPLATE_TABLE_NAME, $record, "id='".(int)$lease['id']."'");
                } else {
                    $db->insert_query(AF_ADAPTIVETHEMEFRAMEWORK_TEMPLATE_TABLE_NAME, $record);
                }
            }

            af_adaptivethemeframework_activation_stage(($exists ? 'write_index' : 'insert_index_override') . '[sid=' . $sid . ']');
            if ($exists) {
                $db->update_query('templates', ['template' => $seed, 'dateline' => $now], "tid='".(int)$live['tid']."'");
                $tid = (int)$live['tid'];
            } else {
                // MyBB 1.8.40 columns: title, template, sid, version, status, dateline.
                $tid = (int)$db->insert_query('templates', ['title' => 'index', 'template' => $seed, 'sid' => $sid, 'version' => '1840', 'status' => '', 'dateline' => $now]);
                $db->update_query(AF_ADAPTIVETHEMEFRAMEWORK_TEMPLATE_TABLE_NAME, ['template_tid' => $tid], "template_sid='{$sid}' AND template_name='index'");
            }
            af_adaptivethemeframework_activation_stage('verify_index[sid=' . $sid . ']');
            $installed = $db->fetch_array($db->simple_select('templates', 'tid,template', "title='index' AND sid='{$sid}'", ['limit' => 1]));
            if (!$installed || !hash_equals($seedChecksum, af_adaptivethemeframework_checksum((string)$installed['template']))) {
                $db->update_query(AF_ADAPTIVETHEMEFRAMEWORK_TEMPLATE_TABLE_NAME, ['ownership_state' => 'write_failed', 'updated_at' => $now], "template_sid='{$sid}' AND template_name='index'");
                throw new RuntimeException('installed index checksum verification failed for template set ' . $sid);
            }
        }
        return true;
    } catch (Throwable $error) {
        return af_adaptivethemeframework_activation_failure($error);
    }
}

function af_adaptivethemeframework_release_index(): bool
{
    global $db;
    if (!is_object($db)) {
        return true;
    }
    if (!$db->table_exists(AF_ADAPTIVETHEMEFRAMEWORK_TEMPLATE_TABLE_NAME)) {
        return true;
    }
    $now = defined('TIME_NOW') ? TIME_NOW : time();
    $query = $db->simple_select(AF_ADAPTIVETHEMEFRAMEWORK_TEMPLATE_TABLE_NAME, '*', "template_name='index' AND ownership_state='owned'");
    $ok = true;
    while ($lease = $db->fetch_array($query)) {
        $sid = (int)$lease['template_sid'];
        $live = $db->fetch_array($db->simple_select('templates', '*', "title='index' AND sid='{$sid}'", ['limit' => 1]));
        if (!$live || af_adaptivethemeframework_checksum((string)$live['template']) !== $lease['atf_installed_checksum']) {
            $db->update_query(AF_ADAPTIVETHEMEFRAMEWORK_TEMPLATE_TABLE_NAME, ['ownership_state' => 'restore_conflict', 'current_content' => (string)($live['template'] ?? ''), 'updated_at' => $now], "id='".(int)$lease['id']."'");
            $ok = false;
            continue;
        }
        if ((int)$lease['previous_exists'] === 1) {
            $db->update_query('templates', ['template' => $lease['previous_content'], 'dateline' => (int)$lease['previous_dateline']], "tid='".(int)$live['tid']."'");
        } else {
            $db->delete_query('templates', "tid='".(int)$live['tid']."'");
        }
        $restored = $db->fetch_array($db->simple_select('templates', 'tid,template,dateline', "title='index' AND sid='{$sid}'", ['limit' => 1]));
        $restoreVerified = (int)$lease['previous_exists'] === 1
            ? ($restored && hash_equals((string)$lease['previous_checksum'], af_adaptivethemeframework_checksum((string)$restored['template'])))
            : !$restored;
        if (!$restoreVerified) {
            $db->update_query(AF_ADAPTIVETHEMEFRAMEWORK_TEMPLATE_TABLE_NAME, ['ownership_state' => 'restore_failed', 'current_content' => (string)($restored['template'] ?? ''), 'updated_at' => $now], "id='".(int)$lease['id']."'");
            $ok = false;
            break;
        }
        $db->update_query(AF_ADAPTIVETHEMEFRAMEWORK_TEMPLATE_TABLE_NAME, ['ownership_state' => 'restored', 'updated_at' => $now, 'restored_at' => $now], "id='".(int)$lease['id']."'");
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
