<?php
/**
 * AF Addon: AdvancedProfileFields
 * MyBB 1.8.40, PHP 8.0–8.5
 */

if (!defined('IN_MYBB')) { die('No direct access'); }
if (!defined('AF_ADDONS')) { die('AdvancedFunctionality core required'); }

define('AF_APF_ID', 'advancedprofilefields');
define('AF_APF_BASE', AF_ADDONS . AF_APF_ID . '/');
define('AF_APF_ASSETS_DIR', AF_APF_BASE . 'assets/');
define('AF_APF_TPL_FILE', AF_APF_BASE . 'templates/advancedprofilefields.html');
define('AF_APF_ASSET_MARK', '<!--af_apf_assets-->');
define('AF_APF_SIG', 'af-apf-');
define('AF_APF_VALUES_TABLE', 'af_apf_values');
define('AF_APF_SECONDARY_AVATAR_KEY', 'profile_secondary_avatar');
define('AF_APF_SECONDARY_AVATAR_DIR', 'uploads/avatars');

/* -------------------- LANG -------------------- */

function af_advancedprofilefields_load_lang(bool $admin = false): void
{
    global $lang;

    if (!is_object($lang)) {
        if (class_exists('MyLanguage')) {
            $lang = new MyLanguage();
        } else {
            return;
        }
    }

    // Языки аддона генерируются ядром AF:
    // front: inc/languages/*/advancedfunctionality_advancedprofilefields.lang.php
    // admin: inc/languages/*/admin/advancedfunctionality_advancedprofilefields.lang.php
    $file = $admin
        ? 'advancedfunctionality_'.AF_APF_ID.'.lang.php'
        : 'advancedfunctionality_'.AF_APF_ID.'.lang.php';

    if ($admin) {
        $lang->load($file, true, true);
    } else {
        $lang->load($file);
    }
}

/* -------------------- SETTINGS -------------------- */

function af_apf_setting_name(string $k): string
{
    return 'af_' . AF_APF_ID . '_' . $k;
}

function af_apf_is_enabled(): bool
{
    global $mybb;
    $key = af_apf_setting_name('enabled');
    return !empty($mybb->settings[$key]);
}

function af_apf_parse_disable_conditions(string $raw): array
{
    $out = [];
    $lines = preg_split('~\R~', $raw);
    if (!is_array($lines)) {
        return $out;
    }

    foreach ($lines as $line) {
        $line = trim((string)$line);
        if ($line === '') {
            continue;
        }

        $script = '';
        $action = null;

        $qPos = strpos($line, '?');
        if ($qPos === false) {
            $script = strtolower($line);
        } else {
            $script = strtolower(trim(substr($line, 0, $qPos)));
            $query  = trim(substr($line, $qPos + 1));

            if ($query !== '') {
                $parts = explode('&', $query);
                foreach ($parts as $part) {
                    $part = trim((string)$part);
                    if ($part === '') {
                        continue;
                    }

                    $eqPos = strpos($part, '=');
                    if ($eqPos === false) {
                        continue;
                    }

                    $k = strtolower(trim(substr($part, 0, $eqPos)));
                    $v = strtolower(trim(substr($part, $eqPos + 1)));
                    if ($k === 'action') {
                        $action = $v;
                        break;
                    }
                }
            }
        }

        if ($script === '') {
            continue;
        }

        $script = strtolower(basename(str_replace('\\', '/', $script)));
        if ($script === '') {
            continue;
        }

        $out[] = ['script' => $script, 'action' => $action];
    }

    return $out;
}

function af_apf_assets_disabled_for_current_page(): bool
{
    if (function_exists('af_is_blacklisted')) {
        if (af_is_blacklisted(AF_APF_ID)) {
            return true;
        }
    }

    global $mybb;

    $script = defined('THIS_SCRIPT') ? strtolower((string)THIS_SCRIPT) : '';
    if ($script !== '') {
        $script = strtolower(basename(str_replace('\\', '/', $script)));
    }
    if ($script === '') {
        return false;
    }

    // Жёсткое правило по ТЗ.
    if ($script === 'index.php') {
        return true;
    }

    $action = strtolower((string)($mybb->input['action'] ?? ''));
    $lines = ['index.php'];

    $customRaw = trim((string)($mybb->settings['af_apf_assets_blacklist'] ?? ''));
    if ($customRaw !== '') {
        $lines[] = $customRaw;
    }

    $conditions = af_apf_parse_disable_conditions(implode("\n", $lines));
    foreach ($conditions as $cond) {
        $condScript = strtolower((string)($cond['script'] ?? ''));
        if ($condScript === '' || $condScript !== $script) {
            continue;
        }

        $condAction = $cond['action'] ?? null;
        if ($condAction === null || $condAction === '') {
            return true;
        }

        if ($action === strtolower((string)$condAction)) {
            return true;
        }
    }

    return false;
}

function af_apf_asset_ver(string $absPath): int
{
    return is_file($absPath) ? (int)@filemtime($absPath) : 0;
}

function af_apf_add_ver(string $url, int $ver): string
{
    if ($ver <= 0) {
        return $url;
    }
    return $url.(strpos($url, '?') === false ? '?' : '&').'v='.$ver;
}

function af_apf_strip_own_assets(string &$page): void
{
    if ($page === '') {
        return;
    }

    $page = str_replace(AF_APF_ASSET_MARK, '', $page);
    $page = preg_replace('~<link\b[^>]*\bhref=("|\')[^"\']*?/inc/plugins/advancedfunctionality/addons/advancedprofilefields/assets/[^"\']+\.css(?:\?[^"\']*)?\1[^>]*>\s*~i', '', $page);
    $page = preg_replace('~<script\b[^>]*\bsrc=("|\')[^"\']*?/inc/plugins/advancedfunctionality/addons/advancedprofilefields/assets/[^"\']+\.js(?:\?[^"\']*)?\1[^>]*>\s*</script>\s*~i', '', $page);
}

function af_apf_dedupe_own_assets(string &$page): void
{
    if ($page === '') {
        return;
    }

    $seen = [];
    $pattern = '~<(link|script)\b[^>]*(?:href|src)=("|\')([^"\']*?/inc/plugins/advancedfunctionality/addons/advancedprofilefields/assets/[^"\']+)\2[^>]*>(?:\s*</script>)?~i';

    $page = preg_replace_callback($pattern, static function (array $m) use (&$seen) {
        $url = html_entity_decode((string)($m[3] ?? ''), ENT_QUOTES, 'UTF-8');
        if ($url === '') {
            return $m[0];
        }

        $path = strtolower((string)parse_url($url, PHP_URL_PATH));
        if ($path === '') {
            $path = strtolower((string)preg_replace('~\?.*$~', '', $url));
        }

        if (isset($seen[$path])) {
            return '';
        }

        $seen[$path] = true;
        return $m[0];
    }, $page) ?? $page;
}

/* -------------------- INSTALL / UNINSTALL -------------------- */

function af_advancedprofilefields_install(): void
{
    global $db;

    af_apf_ensure_values_schema();

    // settings group
    $gid = (int)$db->fetch_field(
        $db->simple_select('settinggroups', 'gid', "name='af_".AF_APF_ID."'", ['limit' => 1]),
        'gid'
    );

    if (!$gid) {
        $group = [
            'name'        => 'af_' . AF_APF_ID,
            'title'       => 'AdvancedProfileFields',
            'description' => 'CSS-классы для дополнительных полей профиля (customfields), постов и тем.',
            'disporder'   => 10,
            'isdefault'   => 0,
        ];
        $gid = (int)$db->insert_query('settinggroups', $group);
    }

    // enabled setting
    $sname = af_apf_setting_name('enabled');
    $sid = (int)$db->fetch_field(
        $db->simple_select('settings', 'sid', "name='".$db->escape_string($sname)."'", ['limit' => 1]),
        'sid'
    );

    if (!$sid) {
        $setting = [
            'name'        => $sname,
            'title'       => 'Включить AdvancedProfileFields',
            'description' => 'Добавляет CSS-классы к дополнительным полям профиля (customfields) и к строкам "Сообщений/Тем".',
            'optionscode' => 'yesno',
            'value'       => '1',
            'disporder'   => 1,
            'gid'         => $gid,
        ];
        $db->insert_query('settings', $setting);
    }

    $assetsBlacklistSid = (int)$db->fetch_field(
        $db->simple_select('settings', 'sid', "name='af_apf_assets_blacklist'", ['limit' => 1]),
        'sid'
    );
    if (!$assetsBlacklistSid) {
        $db->insert_query('settings', [
            'name'        => 'af_apf_assets_blacklist',
            'title'       => 'Asset blacklist (страницы без JS/CSS)',
            'description' => "По одному условию в строке: script.php или script.php?action=xxx.\nindex.php отключён всегда (жёстко).",
            'optionscode' => "textarea\n6|70",
            'value'       => 'index.php',
            'disporder'   => 2,
            'gid'         => $gid,
        ]);
    }

    if (function_exists('rebuild_settings')) {
        rebuild_settings();
    }

    // Аддон-шаблоны (не критично для работы, но по твоему канону — держим source of truth)
    af_apf_templates_install_or_update();
}

function af_advancedprofilefields_uninstall(): void
{
    global $db;

    // На всякий — откатим патчи, если остались
    af_apf_apply_template_patches(false);

    // remove addon templates
    $db->delete_query('templates', "title LIKE 'af_apf_%'");

    // remove settings
    $db->delete_query('settings', "name LIKE 'af_".AF_APF_ID."_%'");
    $db->delete_query('settings', "name='af_apf_assets_blacklist'");
    $db->delete_query('settinggroups', "name='af_".AF_APF_ID."'");
    if ($db->table_exists(AF_APF_VALUES_TABLE)) {
        $db->drop_table(AF_APF_VALUES_TABLE);
    }

    if (function_exists('rebuild_settings')) {
        rebuild_settings();
    }
}

/* -------------------- ACTIVATE / DEACTIVATE -------------------- */

function af_advancedprofilefields_activate(): void
{
    af_apf_ensure_values_schema();
    af_apf_templates_install_or_update();
    af_apf_apply_template_patches(true);

    if (function_exists('rebuild_settings')) {
        rebuild_settings();
    }
}

function af_advancedprofilefields_deactivate(): void
{
    af_apf_apply_template_patches(false);

    if (function_exists('rebuild_settings')) {
        rebuild_settings();
    }
}

/* -------------------- TEMPLATE IMPORT (addon templates) -------------------- */

function af_apf_templates_install_or_update(): void
{
    global $db;

    if (!is_file(AF_APF_TPL_FILE)) {
        return;
    }

    $raw = @file_get_contents(AF_APF_TPL_FILE);
    if ($raw === false || $raw === '') {
        return;
    }

    // Формат:
    // <!-- TEMPLATE: af_apf_xxx -->
    // ...html...
    $parts = preg_split('~<!--\s*TEMPLATE:\s*([a-zA-Z0-9_\-]+)\s*-->~', $raw, -1, PREG_SPLIT_DELIM_CAPTURE);
    if (!is_array($parts) || count($parts) < 3) {
        return;
    }

    // parts: [0]=до первого, [1]=name, [2]=content, [3]=name, [4]=content...
    for ($i = 1; $i < count($parts); $i += 2) {
        $name = trim((string)$parts[$i]);
        $tpl  = (string)$parts[$i + 1];

        if ($name === '' || $tpl === '') {
            continue;
        }

        $title = $db->escape_string($name);
        $existing = $db->simple_select('templates', 'tid', "title='{$title}' AND sid='-1'", ['limit' => 1]);
        $tid = (int)$db->fetch_field($existing, 'tid');

        $row = [
            'title'    => $title,
            'template' => $db->escape_string($tpl),
            'sid'      => -1,
            'version'  => '1839',
            'dateline' => TIME_NOW,
        ];

        if ($tid) {
            $db->update_query('templates', $row, "tid='{$tid}'");
        } else {
            $db->insert_query('templates', $row);
        }
    }
}

/* -------------------- CORE: TEMPLATE PATCHES -------------------- */
/* -------------------- CORE: TEMPLATE PATCHES (DB-level, no find_replace_templatesets) -------------------- */

function af_apf_get_templates(string $title): array
{
    global $db;

    $rows = [];
    $q = $db->simple_select('templates', 'tid,sid,title,template', "title='".$db->escape_string($title)."'");
    while ($r = $db->fetch_array($q)) {
        $rows[] = $r;
    }

    return $rows;
}

function af_apf_update_template(int $tid, string $template): void
{
    global $db;

    $db->update_query('templates', [
        'template'  => $db->escape_string($template),
        'dateline'  => TIME_NOW,
    ], "tid='".(int)$tid."'");
}

function af_apf_preg_replace_limited(string $pattern, string $replacement, string $subject, int $limit): array
{
    // Возвращает [newSubject, replacedCount]
    $count = 0;

    if ($limit === 0) {
        return [$subject, 0];
    }

    if ($limit < 0) {
        $new = preg_replace($pattern, $replacement, $subject, -1, $count);
        return [is_string($new) ? $new : $subject, (int)$count];
    }

    $new = preg_replace($pattern, $replacement, $subject, $limit, $count);
    return [is_string($new) ? $new : $subject, (int)$count];
}

function af_apf_apply_rules_to_template(string $template, array $rules): array
{
    // rules: [ [pattern, replacement, limit], ... ]
    $changed = false;
    $applied = 0;

    foreach ($rules as $rule) {
        $pattern = $rule[0];
        $repl    = $rule[1];
        $limit   = $rule[2];

        [$new, $cnt] = af_apf_preg_replace_limited($pattern, $repl, $template, $limit);
        if ($cnt > 0 && $new !== $template) {
            $template = $new;
            $changed = true;
            $applied += $cnt;
        }
    }

    return [$template, $changed, $applied];
}

function af_apf_apply_template_patches(bool $enable): void
{
    global $db;

    // Кнопка "Профиль" (вставляем один раз, с маркером для безопасного отката)
    $profileBtnHtml =
        '<!-- af_apf_profile_btn -->'
        . '<a href="member.php?action=profile&amp;uid={$post[\'uid\']}" class="postbit_profile af-apf-postbit-profile" data-af-title="Профиль" title="Профиль">'
        . '<span class="af-apf-ico-profile" aria-hidden="true"></span>'
        . '<span class="af-apf-title">Профиль</span>'
        . '</a>'
        . '<!-- /af_apf_profile_btn -->';


    $targets = [

        /* =========================
           PROFILE: custom fields (member.php profile)
           ========================= */
        'member_profile_customfields_field' => [
            'enable' => [
                ['~(?:<span[^>]*class="af-apf-name"[^>]*>\s*)+\{\$customfield\[\'name\'\]\}(?:\s*</span>\s*)+~is',
                    '{$customfield[\'name\']}',
                    -1
                ],
                ['~(?:<span[^>]*class="af-apf-value[^"]*"[^>]*>\s*)+\{\$customfield\[\'value\'\]\}(?:\s*</span>\s*)+~is',
                    '{$customfield[\'value\']}',
                    -1
                ],
                ['~\{\$customfield\[\'name\'\]\}~i',
                    '<span class="af-apf-name">{$customfield[\'name\']}</span>',
                    1
                ],
                ['~\{\$customfield\[\'value\'\]\}~i',
                    '<span class="af-apf-value scaleimages">{$customfield[\'value\']}</span>',
                    1
                ],
                ['~<tr(?![^>]*\baf-apf-row\b)([^>]*)>~i',
                    '<tr class="af-apf-row"$1>',
                    1
                ],
            ],
            'disable' => [
                ['~<span\s+class="af-apf-name">\s*\{\$customfield\[\'name\'\]\}\s*</span>~i', '{$customfield[\'name\']}', -1],
                ['~<span\s+class="af-apf-value\s+scaleimages">\s*\{\$customfield\[\'value\'\]\}\s*</span>~i', '{$customfield[\'value\']}', -1],
                ['~\s+\baf-apf-row\b~i', '', -1],
            ],
        ],

        'member_profile_customfields_field_multi' => [
            'enable' => [
                ['~<ul(?![^>]*\baf-apf-multi\b)([^>]*)>~i', '<ul class="af-apf-multi"$1>', 1],
            ],
            'disable' => [
                ['~\s+\baf-apf-multi\b~i', '', -1],
            ],
        ],

        'member_profile_customfields_field_multi_item' => [
            'enable' => [
                ['~<li(?![^>]*\baf-apf-multi-item\b)([^>]*)>~i', '<li class="af-apf-multi-item"$1>', 1],
            ],
            'disable' => [
                ['~\s+\baf-apf-multi-item\b~i', '', -1],
            ],
        ],

        /* =========================
           PROFILE: core rows (member profile + statistics)
           ========================= */
        'member_profile' => [
            'enable' => [
                ['~(<strong>\s*(?:\{\$lang->total_posts\}|Сообщений)\s*</strong>)~iu',
                    '<strong><span class="af-apf-core-label af-apf-core-posts">$1</span></strong>',
                    1
                ],
                ['~(<strong>\s*(?:\{\$lang->total_threads\}|Тем)\s*</strong>)~iu',
                    '<strong><span class="af-apf-core-label af-apf-core-threads">$1</span></strong>',
                    1
                ],
                ['~(<strong>\s*(?:\{\$lang->registered\}|\{\$lang->joined\}|Зарегистрирован)\s*</strong>)~iu',
                    '<strong><span class="af-apf-core-label af-apf-core-registered">$1</span></strong>',
                    1
                ],

                ['~(<tr[^>]*>\s*<td[^>]*>\s*<strong>\s*<span class="af-apf-core-label af-apf-core-posts">.*?</span>\s*</strong>\s*</td>\s*<td[^>]*>)\s*(.*?)\s*(</td>)~is',
                    '$1<span class="af-apf-core-value af-apf-core-posts">$2</span>$3',
                    1
                ],
                ['~(<tr[^>]*>\s*<td[^>]*>\s*<strong>\s*<span class="af-apf-core-label af-apf-core-threads">.*?</span>\s*</strong>\s*</td>\s*<td[^>]*>)\s*(.*?)\s*(</td>)~is',
                    '$1<span class="af-apf-core-value af-apf-core-threads">$2</span>$3',
                    1
                ],
                ['~(<tr[^>]*>\s*<td[^>]*>\s*<strong>\s*<span class="af-apf-core-label af-apf-core-registered">.*?</span>\s*</strong>\s*</td>\s*<td[^>]*>)\s*(.*?)\s*(</td>)~is',
                    '$1<span class="af-apf-core-value af-apf-core-registered">$2</span>$3',
                    1
                ],
            ],
            'disable' => [
                ['~<strong><span class="af-apf-core-label af-apf-core-posts">(<strong>.*?</strong>)</span></strong>~is', '$1', -1],
                ['~<strong><span class="af-apf-core-label af-apf-core-threads">(<strong>.*?</strong>)</span></strong>~is', '$1', -1],
                ['~<strong><span class="af-apf-core-label af-apf-core-registered">(<strong>.*?</strong>)</span></strong>~is', '$1', -1],

                ['~<span class="af-apf-core-value af-apf-core-posts">(.*?)</span>~is', '$1', -1],
                ['~<span class="af-apf-core-value af-apf-core-threads">(.*?)</span>~is', '$1', -1],
                ['~<span class="af-apf-core-value af-apf-core-registered">(.*?)</span>~is', '$1', -1],
            ],
        ],

        'member_profile_statistics' => [
            'enable' => [
                ['~(<strong>\s*(?:\{\$lang->total_posts\}|Сообщений)\s*</strong>)~iu',
                    '<strong><span class="af-apf-core-label af-apf-core-posts">$1</span></strong>',
                    1
                ],
                ['~(<strong>\s*(?:\{\$lang->total_threads\}|Тем)\s*</strong>)~iu',
                    '<strong><span class="af-apf-core-label af-apf-core-threads">$1</span></strong>',
                    1
                ],
                ['~(<strong>\s*(?:\{\$lang->registered\}|\{\$lang->joined\}|Зарегистрирован)\s*</strong>)~iu',
                    '<strong><span class="af-apf-core-label af-apf-core-registered">$1</span></strong>',
                    1
                ],

                ['~(<tr[^>]*>\s*<td[^>]*>\s*<strong>\s*<span class="af-apf-core-label af-apf-core-posts">.*?</span>\s*</strong>\s*</td>\s*<td[^>]*>)\s*(.*?)\s*(</td>)~is',
                    '$1<span class="af-apf-core-value af-apf-core-posts">$2</span>$3',
                    1
                ],
                ['~(<tr[^>]*>\s*<td[^>]*>\s*<strong>\s*<span class="af-apf-core-label af-apf-core-threads">.*?</span>\s*</strong>\s*</td>\s*<td[^>]*>)\s*(.*?)\s*(</td>)~is',
                    '$1<span class="af-apf-core-value af-apf-core-threads">$2</span>$3',
                    1
                ],
                ['~(<tr[^>]*>\s*<td[^>]*>\s*<strong>\s*<span class="af-apf-core-label af-apf-core-registered">.*?</span>\s*</strong>\s*</td>\s*<td[^>]*>)\s*(.*?)\s*(</td>)~is',
                    '$1<span class="af-apf-core-value af-apf-core-registered">$2</span>$3',
                    1
                ],
            ],
            'disable' => [
                ['~<strong><span class="af-apf-core-label af-apf-core-posts">(<strong>.*?</strong>)</span></strong>~is', '$1', -1],
                ['~<strong><span class="af-apf-core-label af-apf-core-threads">(<strong>.*?</strong>)</span></strong>~is', '$1', -1],
                ['~<strong><span class="af-apf-core-label af-apf-core-registered">(<strong>.*?</strong>)</span></strong>~is', '$1', -1],

                ['~<span class="af-apf-core-value af-apf-core-posts">(.*?)</span>~is', '$1', -1],
                ['~<span class="af-apf-core-value af-apf-core-threads">(.*?)</span>~is', '$1', -1],
                ['~<span class="af-apf-core-value af-apf-core-registered">(.*?)</span>~is', '$1', -1],
            ],
        ],

        /* =========================
           POSTBIT: custom profile field line
           ========================= */
        'postbit_profilefield' => [
            'enable' => [
                ['~<br\s*/?>\s*(?:<span[^>]*class="af-apf-postbit-field"[^>]*>\s*)*(?:<span[^>]*class="af-apf-name"[^>]*>\s*)*\{\$post\[\'fieldname\'\]\}(?:\s*</span>\s*)*[:：]?\s*(?:<span[^>]*class="af-apf-value[^"]*"[^>]*>\s*)*\{\$post\[\'fieldvalue\'\]\}(?:\s*</span>\s*)*(?:</span>\s*)*~is',
                    '<br /><span class="af-apf-postbit-field"><span class="af-apf-name">{$post[\'fieldname\']}</span>: <span class="af-apf-value scaleimages">{$post[\'fieldvalue\']}</span></span>',
                    1
                ],
            ],
            'disable' => [
                ['~<br\s*/?>\s*<span class="af-apf-postbit-field"><span class="af-apf-name">\{\$post\[\'fieldname\'\]\}</span>:\s*<span class="af-apf-value scaleimages">\{\$post\[\'fieldvalue\'\]\}</span></span>~is',
                    '<br />{$post[\'fieldname\']}: {$post[\'fieldvalue\']}',
                    1
                ],
                ['~<span[^>]*class="af-apf-postbit-field"[^>]*>~i', '', -1],
                ['~<span[^>]*class="af-apf-name"[^>]*>~i', '', -1],
                ['~<span[^>]*class="af-apf-value[^"]*"[^>]*>~i', '', -1],
            ],
        ],

        /* =========================
           POSTBIT: reputation
           ========================= */
        'postbit_reputation' => [
            'enable' => [
                ['~<span\s+class="af-apf-stat\s+af-apf-stat-reputation">\s*(<span\s+class="af-apf-stat\s+af-apf-stat-reputation">.*?</span>)\s*</span>~is',
                    '$1',
                    -1
                ],
                ['~(?!\s*<br\s*/?>\s*<span\s+class="af-apf-stat\s+af-apf-stat-reputation">)\s*<br\s*/?>\s*\{\$lang->postbit_reputation\}\s*\{\$post\[[\'"]userreputation[\'"]\]\}~i',
                    '<br /><span class="af-apf-stat af-apf-stat-reputation">{$lang->postbit_reputation} {$post[\'userreputation\']}</span>',
                    1
                ],
                ['~(?!\s*<br\s*/?>\s*<span\s+class="af-apf-stat\s+af-apf-stat-reputation">)\s*<br\s*/?>\s*Репутация\s*(?:[:：])?\s*(\{\$post\[[\'"]userreputation[\'"]\]\})~iu',
                    '<br /><span class="af-apf-stat af-apf-stat-reputation">Репутация: $1</span>',
                    1
                ],
            ],
            'disable' => [
                ['~<br\s*/?>\s*<span class="af-apf-stat af-apf-stat-reputation">\s*\{\$lang->postbit_reputation\}\s*\{\$post\[[\'"]userreputation[\'"]\]\}\s*</span>~i',
                    '<br />{$lang->postbit_reputation} {$post[\'userreputation\']}',
                    1
                ],
                ['~<br\s*/?>\s*<span class="af-apf-stat af-apf-stat-reputation">\s*Репутация:\s*(\{\$post\[[\'"]userreputation[\'"]\]\})\s*</span>~iu',
                    '<br />Репутация $1',
                    1
                ],
            ],
        ],

        /* =========================
           POSTBIT: warning level
           ========================= */
        'postbit_warninglevel' => [
            'enable' => [
                ['~<span\s+class="af-apf-stat\s+af-apf-stat-warninglevel">\s*(<span\s+class="af-apf-stat\s+af-apf-stat-warninglevel">.*?</span>)\s*</span>~is',
                    '$1',
                    -1
                ],
                ['~(?!\s*<br\s*/?>\s*<span\s+class="af-apf-stat\s+af-apf-stat-warninglevel">)\s*<br\s*/?>\s*\{\$lang->postbit_warning_level\}\s*<a\s+href="\{\$warning_link\}">\{\$warning_level\}</a>~i',
                    '<br /><span class="af-apf-stat af-apf-stat-warninglevel">{$lang->postbit_warning_level} <a href="{$warning_link}">{$warning_level}</a></span>',
                    1
                ],
            ],
            'disable' => [
                ['~<br\s*/?>\s*<span class="af-apf-stat af-apf-stat-warninglevel">\s*\{\$lang->postbit_warning_level\}\s*<a\s+href="\{\$warning_link\}">\{\$warning_level\}</a>\s*</span>~i',
                    '<br />{$lang->postbit_warning_level} <a href="{$warning_link}">{$warning_level}</a>',
                    1
                ],
            ],
        ],

        /* =========================
           FIX: Кнопка "Профиль" перед {$post['button_email']}
           ========================= */
        'postbit' => [
            'enable' => [
                // Идемпотентность: чистим наш блок, если уже был
                ['~<!--\s*af_apf_profile_btn\s*-->.*?<!--\s*/af_apf_profile_btn\s*-->~is', '', -1],

                // Вставка строго перед {$post['button_email']} внутри author_buttons
                ['~(<div[^>]*class="postbit_buttons\s+author_buttons\s+float_left"[^>]*>)([\s\S]*?)(\{\$post\[[\'"]button_email[\'"]\]\})~is',
                    '$1$2' . "\n" . $profileBtnHtml . "\n" . '$3',
                    1
                ],
            ],
            'disable' => [
                ['~<!--\s*af_apf_profile_btn\s*-->.*?<!--\s*/af_apf_profile_btn\s*-->~is', '', -1],
            ],
        ],

        'postbit_classic' => [
            'enable' => [
                ['~<!--\s*af_apf_profile_btn\s*-->.*?<!--\s*/af_apf_profile_btn\s*-->~is', '', -1],
                ['~(<div[^>]*class="postbit_buttons\s+author_buttons\s+float_left"[^>]*>)([\s\S]*?)(\{\$post\[[\'"]button_email[\'"]\]\})~is',
                    '$1$2' . "\n" . $profileBtnHtml . "\n" . '$3',
                    1
                ],
            ],
            'disable' => [
                ['~<!--\s*af_apf_profile_btn\s*-->.*?<!--\s*/af_apf_profile_btn\s*-->~is', '', -1],
            ],
        ],

        // APF owns the secondary-avatar component; the native avatar form stays intact.
        'usercp_avatar' => [
            'enable' => [
                ['~\\s*<!--\\s*af_apf_secondary_avatar\\s*-->.*?<!--\\s*/af_apf_secondary_avatar\\s*-->\\s*~is', "\n", -1],
                ['~(</div>\\s*</main>)~i', "<!-- af_apf_secondary_avatar -->\n{\$af_apf_secondary_avatar}\n<!-- /af_apf_secondary_avatar -->\n$1", 1],
            ],
            'disable' => [
                ['~\\s*<!--\\s*af_apf_secondary_avatar\\s*-->.*?<!--\\s*/af_apf_secondary_avatar\\s*-->\\s*~is', "\n", -1],
            ],
        ],

        /* =========================
           POSTBIT: author_statistics (posts/threads/registered)
           ========================= */
        'postbit_author_user' => [
            'enable' => [
                ['~<span\s+class="af-apf-stat\s+af-apf-stat-posts">\s*(<span\s+class="af-apf-stat\s+af-apf-stat-posts">.*?</span>)\s*</span>~is', '$1', -1],
                ['~<span\s+class="af-apf-stat\s+af-apf-stat-threads">\s*(<span\s+class="af-apf-stat\s+af-apf-stat-threads">.*?</span>)\s*</span>~is', '$1', -1],
                ['~<span\s+class="af-apf-stat\s+af-apf-stat-registered">\s*(<span\s+class="af-apf-stat\s+af-apf-stat-registered">.*?</span>)\s*</span>~is', '$1', -1],

                ['~(?!<span class="af-apf-stat af-apf-stat-posts">)\{\$lang->postbit_posts\}\s*[:：]?\s*\{\$post\[\'postnum\'\]\}~i',
                    '<span class="af-apf-stat af-apf-stat-posts">{$lang->postbit_posts} {$post[\'postnum\']}</span>',
                    -1
                ],
                ['~(?!<span class="af-apf-stat af-apf-stat-threads">)\{\$lang->postbit_threads\}\s*[:：]?\s*\{\$post\[\'threadnum\'\]\}~i',
                    '<span class="af-apf-stat af-apf-stat-threads">{$lang->postbit_threads} {$post[\'threadnum\']}</span>',
                    -1
                ],
                ['~(?!<span class="af-apf-stat af-apf-stat-registered">)\{\$lang->postbit_joined\}\s*[:：]?\s*\{\$post\[\'userregdate\'\]\}~i',
                    '<span class="af-apf-stat af-apf-stat-registered">{$lang->postbit_joined} {$post[\'userregdate\']}</span>',
                    -1
                ],
                ['~(?!<span class="af-apf-stat af-apf-stat-registered">)\{\$lang->postbit_registered\}\s*[:：]?\s*\{\$post\[\'userregdate\'\]\}~i',
                    '<span class="af-apf-stat af-apf-stat-registered">{$lang->postbit_registered} {$post[\'userregdate\']}</span>',
                    -1
                ],
                ['~(?!<span class="af-apf-stat af-apf-stat-registered">)\{\$lang->postbit_regdate\}\s*[:：]?\s*\{\$post\[\'userregdate\'\]\}~i',
                    '<span class="af-apf-stat af-apf-stat-registered">{$lang->postbit_regdate} {$post[\'userregdate\']}</span>',
                    -1
                ],
            ],
            'disable' => [
                ['~<span class="af-apf-stat af-apf-stat-posts">\{\$lang->postbit_posts\}\s*\{\$post\[\'postnum\'\]\}</span>~i',
                    '{$lang->postbit_posts} {$post[\'postnum\']}',
                    -1
                ],
                ['~<span class="af-apf-stat af-apf-stat-threads">\{\$lang->postbit_threads\}\s*\{\$post\[\'threadnum\'\]\}</span>~i',
                    '{$lang->postbit_threads} {$post[\'threadnum\']}',
                    -1
                ],
                ['~<span class="af-apf-stat af-apf-stat-registered">\{\$lang->postbit_(?:joined|registered|regdate)\}\s*\{\$post\[\'userregdate\'\]\}</span>~i',
                    '{$lang->postbit_joined} {$post[\'userregdate\']}',
                    -1
                ],
            ],
        ],
    ];

    $touchedSids = [];

    foreach ($targets as $title => $def) {
        $templates = af_apf_get_templates($title);
        if (!$templates) {
            continue;
        }

        foreach ($templates as $row) {
            $tid = (int)$row['tid'];
            $sid = (int)$row['sid'];
            $tpl = (string)$row['template'];

            if ($tpl === '') {
                continue;
            }

            $rules = $enable ? ($def['enable'] ?? []) : ($def['disable'] ?? []);
            if (!$rules) {
                continue;
            }

            [$newTpl, $changed] = af_apf_apply_rules_to_template($tpl, $rules);

            if ($changed && $newTpl !== $tpl) {
                af_apf_update_template($tid, $newTpl);
                $touchedSids[] = $sid;
            }
        }
    }

    // принудительно чистим кеш шаблонов мастера/дефолта
    $touchedSids[] = -1;
    $touchedSids[] = 1;

    if (function_exists('af_apf_purge_templates_cache')) {
        af_apf_purge_templates_cache($touchedSids);
    }

    if (function_exists('rebuild_settings')) {
        rebuild_settings();
    }
}

function af_apf_is_patched(): bool
{
    global $db;

    if (!is_object($db)) {
        return false;
    }

    // Не привязываемся к sid=-1: патч может быть в sid=1/теме
    $q = $db->simple_select(
        'templates',
        'tid',
        "title IN ('postbit_profilefield','member_profile','member_profile_customfields_field','usercp_profile_customfield','member_register_customfield','postbit_author_user')
         AND template LIKE '%af-apf-%'",
        ['limit' => 1]
    );

    return (int)$db->fetch_field($q, 'tid') > 0;
}

function af_apf_purge_templates_cache(array $sids = []): void
{
    global $cache;

    $sids = array_values(array_unique(array_map('intval', $sids)));
    $sids = array_values(array_filter($sids, static fn($x) => $x !== 0)); // 0 нам не нужен

    if (is_object($cache) && method_exists($cache, 'delete')) {
        // В MyBB шаблоны кешируются по templates-<sid>
        foreach ($sids as $sid) {
            $cache->delete('templates-' . $sid);
        }

        // На всякий: часто мастер/дефолт тоже дергается
        $cache->delete('templates-1');
        $cache->delete('templates--1');
    }
}

/* -------------------- FRONT HOOKS -------------------- */

function af_advancedprofilefields_init(): void
{
    global $plugins;
    if (!is_object($plugins) || !af_apf_is_enabled() || !empty($GLOBALS['af_apf_hooks_registered'])) {
        return;
    }
    $GLOBALS['af_apf_hooks_registered'] = true;
    $plugins->add_hook('usercp_start', 'af_apf_usercp_start', 5);
    $plugins->add_hook('usercp_avatar_end', 'af_apf_usercp_avatar_end', 100);
}

function af_advancedprofilefields_pre_output(&$page = ''): void
{
    if (!af_apf_is_enabled()) {
        return;
    }

    if (!is_string($page) || $page === '') {
        return;
    }

    // Remove legacy/central copies before making the component-aware decision.
    af_apf_strip_own_assets($page);
    af_apf_dedupe_own_assets($page);

    if (af_apf_assets_disabled_for_current_page()) {
        return;
    }

    $hasOutput = strpos($page, AF_APF_SIG) !== false;
    if (function_exists('af_frontend_asset_allowed')
        && !af_frontend_asset_allowed(AF_APF_ID, 'pre_output', null, ['has_apf_output' => $hasOutput])) {
        return;
    }

    // advancedprofilefields.js is intentionally a no-op; only the stylesheet is required.
    $base = rtrim((string)($GLOBALS['mybb']->settings['bburl'] ?? ''), '/')
        . '/inc/plugins/advancedfunctionality/addons/' . AF_APF_ID . '/assets/';
    $css = af_apf_add_ver(
        $base . 'advancedprofilefields.css',
        af_apf_asset_ver(AF_APF_ASSETS_DIR . 'advancedprofilefields.css')
    );
    $tag = AF_APF_ASSET_MARK . "\n<link rel=\"stylesheet\" href=\"" . htmlspecialchars_uni($css) . "\">\n";
    if (stripos($page, '</head>') !== false) {
        $page = preg_replace('~</head>~i', $tag . '</head>', $page, 1) ?? $page;
    } else {
        $page .= "\n" . $tag;
    }
}

/** APF-owned storage for hidden/system fields; no column is added to users. */
function af_apf_ensure_values_schema(): bool
{
    global $db;
    if (!is_object($db) || $db->table_exists(AF_APF_VALUES_TABLE)) {
        return true;
    }
    $collation = method_exists($db, 'build_create_table_collation')
        ? $db->build_create_table_collation()
        : 'ENGINE=InnoDB';
    $db->write_query('CREATE TABLE IF NOT EXISTS ' . TABLE_PREFIX . AF_APF_VALUES_TABLE . " (
        uid int unsigned NOT NULL,
        field_key varchar(64) NOT NULL DEFAULT '',
        field_value varchar(512) NOT NULL DEFAULT '',
        updated_at int unsigned NOT NULL DEFAULT 0,
        PRIMARY KEY (uid, field_key), KEY field_key (field_key)
    ) {$collation}");
    return true;
}

/** Read an APF system field without exposing it through MyBB custom fields. */
function af_apf_get_system_value(int $uid, string $key): string
{
    global $db;
    static $schemaAvailable = null;
    if ($uid <= 0 || !preg_match('~^[a-z0-9_]{1,64}$~', $key)
        || !is_object($db)) {
        return '';
    }
    if ($schemaAvailable === null) {
        $schemaAvailable = $db->table_exists(AF_APF_VALUES_TABLE);
    }
    if (!$schemaAvailable) {
        return '';
    }
    if (!isset($GLOBALS['af_apf_system_value_cache']) || !is_array($GLOBALS['af_apf_system_value_cache'])) {
        $GLOBALS['af_apf_system_value_cache'] = [];
    }
    $cache =& $GLOBALS['af_apf_system_value_cache'];
    $cacheKey = $uid . ':' . $key;
    if (array_key_exists($cacheKey, $cache)) {
        return $cache[$cacheKey];
    }
    $cache[$cacheKey] = (string)$db->fetch_field($db->simple_select(
        AF_APF_VALUES_TABLE,
        'field_value',
        "uid='" . $uid . "' AND field_key='" . $db->escape_string($key) . "'",
        ['limit' => 1]
    ), 'field_value');
    return $cache[$cacheKey];
}

function af_apf_set_system_value(int $uid, string $key, string $value): bool
{
    global $db;
    if ($uid <= 0 || !preg_match('~^[a-z0-9_]{1,64}$~', $key) || !af_apf_ensure_values_schema()) {
        return false;
    }
    if (!isset($GLOBALS['af_apf_system_value_cache']) || !is_array($GLOBALS['af_apf_system_value_cache'])) {
        $GLOBALS['af_apf_system_value_cache'] = [];
    }
    if ($value === '') {
        $db->delete_query(AF_APF_VALUES_TABLE, "uid='{$uid}' AND field_key='" . $db->escape_string($key) . "'");
        $GLOBALS['af_apf_system_value_cache'][$uid . ':' . $key] = '';
        return true;
    }
    $db->replace_query(AF_APF_VALUES_TABLE, [
        'uid' => $uid,
        'field_key' => $db->escape_string($key),
        'field_value' => $db->escape_string($value),
        'updated_at' => defined('TIME_NOW') ? TIME_NOW : time(),
    ]);
    $GLOBALS['af_apf_system_value_cache'][$uid . ':' . $key] = $value;
    return true;
}

/** Public, normalized URL for the APF-owned secondary avatar, or an empty string. */
function af_apf_get_secondary_avatar(int $uid): string
{
    global $mybb;
    $path = af_apf_get_system_value($uid, AF_APF_SECONDARY_AVATAR_KEY);
    if (!preg_match('~^uploads/avatars/secondary_' . $uid . '_[a-f0-9]{32}\\.(?:jpe?g|png|webp|gif)$~', $path)) {
        return '';
    }
    return rtrim((string)($mybb->settings['bburl'] ?? ''), '/') . '/' . $path;
}

function af_apf_secondary_avatar_path(int $uid): string
{
    $path = af_apf_get_system_value($uid, AF_APF_SECONDARY_AVATAR_KEY);
    return preg_match('~^uploads/avatars/secondary_' . $uid . '_[a-f0-9]{32}\\.(?:jpe?g|png|webp|gif)$~', $path)
        ? $path : '';
}

/** Only remove a file whose normalized name proves that it belongs to this uid. */
function af_apf_delete_secondary_avatar_file(int $uid, string $path): void
{
    if ($path === '' || !preg_match('~^uploads/avatars/secondary_' . $uid . '_[a-f0-9]{32}\\.(?:jpe?g|png|webp|gif)$~', $path)) {
        return;
    }
    $absolute = rtrim((string)MYBB_ROOT, '/\\') . '/' . $path;
    if (is_file($absolute)) {
        @unlink($absolute);
    }
}

function af_apf_usercp_start(): void
{
    global $mybb;
    if (!defined('THIS_SCRIPT') || THIS_SCRIPT !== 'usercp.php'
        || (string)$mybb->get_input('action') !== 'do_apf_secondary_avatar') {
        return;
    }
    if (empty($mybb->user['uid'])) {
        error_no_permission();
    }
    verify_post_check((string)$mybb->get_input('my_post_key'));

    $uid = (int)$mybb->user['uid'];
    $oldPath = af_apf_secondary_avatar_path($uid);
    $error = '';

    if (!empty($mybb->input['remove_secondary_avatar'])) {
        if (af_apf_set_system_value($uid, AF_APF_SECONDARY_AVATAR_KEY, '')) {
            af_apf_delete_secondary_avatar_file($uid, $oldPath);
            redirect('usercp.php?action=avatar', 'Дополнительный аватар удалён.');
        }
        $error = 'Не удалось удалить дополнительный аватар.';
    } else {
        $file = $_FILES['secondary_avatar_upload'] ?? null;
        $error = af_apf_validate_secondary_avatar_upload(is_array($file) ? $file : []);
        if ($error === '') {
            $tmp = (string)$file['tmp_name'];
            $mime = af_apf_detect_image_mime($tmp);
            $extensions = [
                'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif',
            ];
            $directory = rtrim((string)MYBB_ROOT, '/\\') . '/' . AF_APF_SECONDARY_AVATAR_DIR;
            if ((!is_dir($directory) && !@mkdir($directory, 0755, true)) || !is_writable($directory)) {
                $error = 'Каталог аватаров недоступен для записи.';
            } else {
                try {
                    $token = bin2hex(random_bytes(16));
                } catch (Throwable $e) {
                    $token = hash('sha256', $uid . ':' . microtime(true) . ':' . mt_rand());
                    $token = substr($token, 0, 32);
                }
                $relative = AF_APF_SECONDARY_AVATAR_DIR . '/secondary_' . $uid . '_' . $token . '.' . $extensions[$mime];
                $destination = rtrim((string)MYBB_ROOT, '/\\') . '/' . $relative;
                if (!move_uploaded_file($tmp, $destination)) {
                    $error = 'Не удалось сохранить загруженный файл.';
                } elseif (!af_apf_set_system_value($uid, AF_APF_SECONDARY_AVATAR_KEY, $relative)) {
                    @unlink($destination);
                    $error = 'Не удалось сохранить дополнительный аватар.';
                } else {
                    @chmod($destination, 0644);
                    af_apf_delete_secondary_avatar_file($uid, $oldPath);
                    redirect('usercp.php?action=avatar', 'Дополнительный аватар обновлён.');
                }
            }
        }
    }

    $GLOBALS['af_apf_secondary_avatar_error'] = $error;
    // Let MyBB render its ordinary avatar page; its do_avatar pipeline is never entered.
    $mybb->input['action'] = 'avatar';
}

function af_apf_detect_image_mime(string $path): string
{
    if ($path === '' || !is_file($path)) {
        return '';
    }
    if (class_exists('finfo')) {
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime = (string)$finfo->file($path);
        if ($mime !== '') {
            return strtolower($mime);
        }
    }
    $info = @getimagesize($path);
    return is_array($info) ? strtolower((string)($info['mime'] ?? '')) : '';
}

/** Return a localized validation error, or an empty string when the upload is safe. */
function af_apf_validate_secondary_avatar_upload(array $file): string
{
    global $mybb;
    $code = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($code === UPLOAD_ERR_NO_FILE) {
        return 'Выберите изображение для загрузки.';
    }
    if ($code !== UPLOAD_ERR_OK) {
        return $code === UPLOAD_ERR_INI_SIZE || $code === UPLOAD_ERR_FORM_SIZE
            ? 'Файл превышает допустимый размер.' : 'Ошибка загрузки файла (код ' . $code . ').';
    }
    $tmp = (string)($file['tmp_name'] ?? '');
    if ($tmp === '' || !is_uploaded_file($tmp)) {
        return 'Загруженный файл не прошёл проверку HTTP upload.';
    }
    $maxKb = max(0, (int)($mybb->settings['maxavatarsize'] ?? 0));
    $size = (int)($file['size'] ?? 0);
    if ($size <= 0 || ($maxKb > 0 && $size > $maxKb * 1024)) {
        return $maxKb > 0
            ? 'Файл превышает лимит ' . $maxKb . ' КБ.' : 'Загружен пустой файл.';
    }
    $info = @getimagesize($tmp);
    $mime = af_apf_detect_image_mime($tmp);
    $allowed = ['image/jpeg' => IMAGETYPE_JPEG, 'image/png' => IMAGETYPE_PNG,
        'image/webp' => IMAGETYPE_WEBP, 'image/gif' => IMAGETYPE_GIF];
    if (!is_array($info) || !isset($allowed[$mime]) || (int)($info[2] ?? 0) !== $allowed[$mime]) {
        return 'Разрешены только настоящие изображения JPG, PNG, WEBP и GIF.';
    }
    return '';
}

function af_apf_usercp_avatar_end(): void
{
    global $mybb;
    $uid = (int)($mybb->user['uid'] ?? 0);
    if ($uid <= 0) {
        $GLOBALS['af_apf_secondary_avatar'] = '';
        return;
    }
    $url = af_apf_get_secondary_avatar($uid);
    $error = trim((string)($GLOBALS['af_apf_secondary_avatar_error'] ?? ''));
    $preview = $url !== ''
        ? '<img src="' . htmlspecialchars_uni($url) . '" alt="Текущий дополнительный аватар" loading="lazy">'
        : '<span class="af-apf-secondary-avatar__empty">Дополнительный аватар отсутствует</span>';
    $remove = $url !== ''
        ? '<button type="submit" class="button atf-button atf-button--danger" name="remove_secondary_avatar" value="1">Удалить</button>' : '';
    $errorHtml = $error !== '' ? '<div class="error af-apf-secondary-avatar__error" role="alert">' . htmlspecialchars_uni($error) . '</div>' : '';
    $maxKb = max(0, (int)($mybb->settings['maxavatarsize'] ?? 0));
    $fileLimit = $maxKb > 0 ? '; размер файла — до ' . $maxKb . ' КБ' : '';
    $GLOBALS['af_apf_secondary_avatar'] = '<section class="atf-card atf-form-section af-apf-secondary-avatar" data-af-apf-secondary-avatar="1">'
        . '<h2>Аватар персонажа</h2>' . $errorHtml
        . '<div class="af-apf-secondary-avatar__layout"><div class="af-apf-secondary-avatar__preview">' . $preview . '</div>'
        . '<form enctype="multipart/form-data" action="usercp.php" method="post" class="atf-stack af-apf-secondary-avatar__form">'
        . '<input type="hidden" name="my_post_key" value="' . htmlspecialchars_uni((string)$mybb->post_code) . '">'
        . '<input type="hidden" name="action" value="do_apf_secondary_avatar">'
        . '<label class="atf-form-row"><span class="atf-form-row__label">Загрузить новое изображение</span>'
        . '<span class="atf-form-row__hint">JPG, PNG, WEBP или GIF' . $fileLimit . '. Рекомендуемый размер: 200×250 px. Изображения большего размера будут автоматически вписаны в область аватара при отображении.</span>'
        . '<input type="file" name="secondary_avatar_upload" class="fileupload" accept="image/jpeg,image/png,image/webp,image/gif"></label>'
        . '<div class="atf-form-actions"><button type="submit" class="button atf-button">Сохранить дополнительный аватар</button>' . $remove . '</div>'
        . '</form></div></section>';
}
