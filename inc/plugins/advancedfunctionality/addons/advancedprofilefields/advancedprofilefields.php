<?php
/**
 * AF Addon: AdvancedProfileFields
 * MyBB 1.8.38–1.8.39, PHP 8.0–8.4
 */

if (!defined('IN_MYBB')) { die('No direct access'); }
if (!defined('AF_ADDONS')) { die('AdvancedFunctionality core required'); }

define('AF_APF_ID', 'advancedprofilefields');
define('AF_APF_BASE', AF_ADDONS . AF_APF_ID . '/');
define('AF_APF_ASSETS_DIR', AF_APF_BASE . 'assets/');
define('AF_APF_TPL_FILE', AF_APF_BASE . 'templates/advancedprofilefields.html');
define('AF_APF_ASSET_MARK', '<!--af_apf_assets-->');
define('AF_APF_SIG', 'af-apf-');

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

/* -------------------- INSTALL / UNINSTALL -------------------- */

function af_advancedprofilefields_install(): void
{
    global $db;

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
    $db->delete_query('settinggroups', "name='af_".AF_APF_ID."'");

    if (function_exists('rebuild_settings')) {
        rebuild_settings();
    }
}

/* -------------------- ACTIVATE / DEACTIVATE -------------------- */

function af_advancedprofilefields_activate(): void
{
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
        . '<a href="member.php?action=profile&amp;uid={$post[\'uid\']}" title="Профиль" class="postbit_profile af-apf-postbit-profile">'
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
    // no-op
}

function af_advancedprofilefields_pre_output(&$page = ''): void
{
    global $mybb;

    if (!af_apf_is_enabled()) {
        return;
    }

    if (!is_string($page) || $page === '' || strpos($page, AF_APF_ASSET_MARK) !== false) {
        return;
    }

    $baseUrl = rtrim((string)$mybb->settings['bburl'], '/');
    $cssUrl  = $baseUrl . '/inc/plugins/advancedfunctionality/addons/' . AF_APF_ID . '/assets/advancedprofilefields.css';
    $jsUrl   = $baseUrl . '/inc/plugins/advancedfunctionality/addons/' . AF_APF_ID . '/assets/advancedprofilefields.js';

    $inject = "\n" . AF_APF_ASSET_MARK . "\n"
        . '<link rel="stylesheet" type="text/css" href="' . htmlspecialchars_uni($cssUrl) . '?v=1.0.0" />' . "\n"
        . '<script type="text/javascript" src="' . htmlspecialchars_uni($jsUrl) . '?v=1.0.0"></script>' . "\n";

    if (stripos($page, '</head>') !== false) {
        $page = preg_replace('~</head>~i', $inject . '</head>', $page, 1);
        return;
    }

    // fallback
    $page = $inject . $page;
}