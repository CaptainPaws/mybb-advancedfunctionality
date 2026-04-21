<?php
/**
 * AF Addon: AdvancedThreadFields
 * MyBB 1.8.38–1.8.39, PHP 8.0–8.4
 *
 * Канон XThreads по функционалу (адаптация под AF):
 * - Определяем поля тем в ACP
 * - Показываем их в newthread/editpost (только для первого поста)
 * - Валидируем/сохраняем значения отдельно
 * - Выводим значения в showthread/forumdisplay
 * - Фильтры в forumdisplay по значениям
 *
 * Хранение EAV:
 *   af_atf_fields  (мета полей)
 *   af_atf_values  (tid, fieldid, value)
 */

if (!defined('IN_MYBB')) { die('No direct access'); }
if (!defined('AF_ADDONS')) { die('AdvancedFunctionality core required'); }

define('AF_ATF_ID', 'advancedthreadfields');
define('AF_ATF_TABLE_FIELDS', 'af_atf_fields');
define('AF_ATF_TABLE_VALUES', 'af_atf_values');
define('AF_ATF_TABLE_GROUPS', 'af_atf_groups');
define('AF_ATF_TABLE_PREFILL', 'af_atf_prefill_tokens');


define('AF_ATF_MARK', '<!--af_atf_assets-->');

define('AF_ATF_ASSET_CSS', 'inc/plugins/advancedfunctionality/addons/'.AF_ATF_ID.'/assets/advancedthreadfields.css');
define('AF_ATF_ASSET_JS',  'inc/plugins/advancedfunctionality/addons/'.AF_ATF_ID.'/assets/advancedthreadfields.js');

define('AF_ATF_TPL_MARK_INPUT', '<!--AF_ATF_INPUT-->');
define('AF_ATF_TPL_MARK_SHOW',  '<!--AF_ATF_SHOW-->');
define('AF_ATF_TPL_MARK_CHIPS', '<!--AF_ATF_CHIPS-->');

function af_atf_sync_languages(): void
{
    // 1) сначала гарантируем, что файлы физически существуют
    af_atf_bootstrap_ensure_langfiles();

    // 2) затем просим ядро AF подхватить языки (если умеет)
    if (function_exists('af_load_addon_lang')) {
        // у разных версий AF сигнатура может отличаться (1 аргумент или 2)
        $ok = false;

        if (class_exists('ReflectionFunction')) {
            try {
                $rf = new ReflectionFunction('af_load_addon_lang');
                if ($rf->getNumberOfParameters() >= 2) {
                    af_load_addon_lang(AF_ATF_ID, false);
                    af_load_addon_lang(AF_ATF_ID, true);
                    $ok = true;
                }
            } catch (Throwable $e) {
                // игнорируем и падаем в fallback ниже
            }
        }

        if (!$ok) {
            // fallback: старый/упрощённый загрузчик
            af_load_addon_lang(AF_ATF_ID);
        }
    }
}
function af_atf_bootstrap_ensure_langfiles(): void
{
    if (!defined('MYBB_ROOT')) {
        return;
    }

    // Собираем список языков, которые могут понадобиться прямо сейчас.
    // Ошибка у тебя про russian/admin, но на всякий — покрываем и фронт.
    $langs = ['russian', 'english'];

    if (isset($GLOBALS['mybb']) && is_object($GLOBALS['mybb'])) {
        $mybb = $GLOBALS['mybb'];

        if (!empty($mybb->settings['cplanguage'])) {
            $langs[] = (string)$mybb->settings['cplanguage'];
        }
        if (!empty($mybb->settings['bblanguage'])) {
            $langs[] = (string)$mybb->settings['bblanguage'];
        }
        if (!empty($mybb->user['language'])) {
            $langs[] = (string)$mybb->user['language'];
        }
    }

    $langs = array_values(array_unique(array_filter(array_map('trim', $langs))));

    foreach ($langs as $lang) {
        af_atf_ensure_langfile($lang, false); // фронт
        af_atf_ensure_langfile($lang, true);  // админка
    }
}

function af_atf_ensure_langfile(string $lang, bool $admin): void
{
    $lang = trim($lang);
    if ($lang === '') {
        $lang = 'english';
    }

    $file = 'advancedfunctionality_' . AF_ATF_ID . '.lang.php';
    $dir  = MYBB_ROOT . 'inc/languages/' . $lang . '/' . ($admin ? 'admin/' : '');
    $path = $dir . $file;

    if (file_exists($path)) {
        return;
    }

    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    if (!is_dir($dir)) {
        return; // нет прав/не создалось — дальше не дёргаемся
    }

    // Пишем минимальный “пустышечный” языковой файл, чтобы include перестал падать.
    // Ключи можно расширить позже — сейчас цель: убрать "does not exist".
    $mode = $admin ? 'admin' : 'front';

    $content =
        "<?php\n"
      . "/**\n"
      . " * Auto-generated placeholder for AF addon: " . AF_ATF_ID . "\n"
      . " * Lang: {$lang}, Mode: {$mode}\n"
      . " * Purpose: prevent missing-language include errors.\n"
      . " */\n"
      . "if (!isset(\$l) || !is_array(\$l)) { \$l = []; }\n"
      . "\$l['af_" . AF_ATF_ID . "_name'] = \$l['af_" . AF_ATF_ID . "_name'] ?? 'AdvancedThreadFields';\n"
      . "\$l['af_" . AF_ATF_ID . "_description'] = \$l['af_" . AF_ATF_ID . "_description'] ?? '';\n";

    if ($admin) {
        $content .= "\$l['af_" . AF_ATF_ID . "_group'] = \$l['af_" . AF_ATF_ID . "_group'] ?? 'AdvancedThreadFields';\n";
        $content .= "\$l['af_" . AF_ATF_ID . "_group_desc'] = \$l['af_" . AF_ATF_ID . "_group_desc'] ?? '';\n";
    }

    @file_put_contents($path, $content, LOCK_EX);
}



/* -------------------- INSTALL / UNINSTALL -------------------- */
function af_advancedthreadfields_install(): void
{
    global $db;

    // Языки: гарантируем генерацию по манифесту
    af_atf_sync_languages();

    // 1) groups
    if (!$db->table_exists(AF_ATF_TABLE_GROUPS)) {
        $db->write_query("
            CREATE TABLE `".TABLE_PREFIX.AF_ATF_TABLE_GROUPS."` (
              `gid` INT UNSIGNED NOT NULL AUTO_INCREMENT,
              `title` VARCHAR(255) NOT NULL,
              `description` TEXT NOT NULL,
              `forums` TEXT NOT NULL,
              `character_mechanic_mode` VARCHAR(16) NOT NULL DEFAULT 'auto',
              `catalog_characters_url` VARCHAR(500) NOT NULL DEFAULT '',
              `catalog_roles_url` VARCHAR(500) NOT NULL DEFAULT '',
              `catalog_characters_label` VARCHAR(255) NOT NULL DEFAULT '',
              `catalog_roles_label` VARCHAR(255) NOT NULL DEFAULT '',
              `show_catalog_cta` TINYINT(1) NOT NULL DEFAULT 0,
              `active` TINYINT(1) NOT NULL DEFAULT 1,
              `sortorder` INT NOT NULL DEFAULT 0,
              PRIMARY KEY (`gid`),
              KEY `active_sort` (`active`,`sortorder`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
        ");
    } else {
        af_atf_db_ensure_group_columns();
    }

    // 2) fields
    if (!$db->table_exists(AF_ATF_TABLE_FIELDS)) {
        $db->write_query("
            CREATE TABLE `".TABLE_PREFIX.AF_ATF_TABLE_FIELDS."` (
              `fieldid` INT UNSIGNED NOT NULL AUTO_INCREMENT,
              `groupid` INT UNSIGNED NOT NULL DEFAULT 0,

              `name` VARCHAR(64) NOT NULL,
              `title` VARCHAR(255) NOT NULL,
              `description` TEXT NOT NULL,
              `type` VARCHAR(32) NOT NULL DEFAULT 'text',
              `options` MEDIUMTEXT NOT NULL,
              `required` TINYINT(1) NOT NULL DEFAULT 0,
              `active` TINYINT(1) NOT NULL DEFAULT 1,

              `show_thread` TINYINT(1) NOT NULL DEFAULT 1,
              `show_forum` TINYINT(1) NOT NULL DEFAULT 0,
              `sortorder` INT NOT NULL DEFAULT 0,
              `maxlen` INT NOT NULL DEFAULT 0,
              `regex` VARCHAR(255) NOT NULL,
              `format` TEXT NOT NULL,

              /* v1.1 */
              `allow_html` TINYINT(1) NOT NULL DEFAULT 0,
              `parse_mycode` TINYINT(1) NOT NULL DEFAULT 1,
              `parse_smilies` TINYINT(1) NOT NULL DEFAULT 1,

              PRIMARY KEY (`fieldid`),
              UNIQUE KEY `name` (`name`),
              KEY `active_sort` (`active`,`sortorder`),
              KEY `groupid` (`groupid`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
        ");
    } else {
        af_atf_db_ensure_columns();
    }

    // 3) values
    if (!$db->table_exists(AF_ATF_TABLE_VALUES)) {
        $db->write_query("
            CREATE TABLE `".TABLE_PREFIX.AF_ATF_TABLE_VALUES."` (
              `tid` INT UNSIGNED NOT NULL,
              `fieldid` INT UNSIGNED NOT NULL,
              `value` MEDIUMTEXT NOT NULL,
              PRIMARY KEY (`tid`,`fieldid`),
              KEY `fieldid` (`fieldid`),
              KEY `tid` (`tid`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
        ");
    }

    af_atf_db_ensure_prefill_table();
    af_atf_ensure_settings();
    af_atf_install_templates();
    af_atf_rebuild_cache(true);
}


function af_advancedthreadfields_uninstall(): void
{
    global $db;

    // Откатим вставки в шаблоны, если вдруг аддон удаляют
    af_atf_revert_template_edits();

    if ($db->table_exists(AF_ATF_TABLE_VALUES)) {
        $db->drop_table(AF_ATF_TABLE_VALUES);
    }
    if ($db->table_exists(AF_ATF_TABLE_FIELDS)) {
        $db->drop_table(AF_ATF_TABLE_FIELDS);
    }
    if ($db->table_exists(AF_ATF_TABLE_GROUPS)) {
        $db->drop_table(AF_ATF_TABLE_GROUPS);
    }
    if ($db->table_exists(AF_ATF_TABLE_PREFILL)) {
        $db->drop_table(AF_ATF_TABLE_PREFILL);
    }

    af_atf_remove_settings();
    af_atf_rebuild_cache(true);
}


/* -------------------- ACTIVATE / DEACTIVATE -------------------- */
function af_advancedthreadfields_activate(): void
{
    global $cache;

    af_atf_sync_languages();

    af_atf_ensure_settings();
    af_atf_db_ensure_group_columns();
    af_atf_db_ensure_prefill_table();
    af_atf_install_templates();

    af_atf_apply_template_edits();

    if (is_object($cache)) {
        $cache->update('af_atf_tpl_state', ['applied' => 1, 'time' => TIME_NOW]);
    }

    af_atf_seed_character_contract_fields();
    af_atf_rebuild_cache(true);
}

function af_atf_db_ensure_prefill_table(): void
{
    global $db;

    if (!is_object($db) || $db->table_exists(AF_ATF_TABLE_PREFILL)) {
        return;
    }

    $db->write_query("
        CREATE TABLE `".TABLE_PREFIX.AF_ATF_TABLE_PREFILL."` (
          `token` CHAR(64) NOT NULL,
          `uid` INT UNSIGNED NOT NULL DEFAULT 0,
          `forum_id` INT UNSIGNED NOT NULL DEFAULT 0,
          `mechanic` VARCHAR(16) NOT NULL DEFAULT '',
          `payload_json` MEDIUMTEXT NOT NULL,
          `created_at` INT UNSIGNED NOT NULL DEFAULT 0,
          `expires_at` INT UNSIGNED NOT NULL DEFAULT 0,
          PRIMARY KEY (`token`),
          KEY `uid_forum` (`uid`,`forum_id`),
          KEY `expires_at` (`expires_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");
}

function af_atf_prefill_store_save(string $token, array $payload): bool
{
    global $db;

    $token = strtolower(trim($token));
    if (!preg_match('~^[a-f0-9]{16,128}$~', $token) || !is_object($db)) {
        return false;
    }

    af_atf_db_ensure_prefill_table();
    if (!$db->table_exists(AF_ATF_TABLE_PREFILL)) {
        return false;
    }

    $values = (array)($payload['values'] ?? []);
    $json = json_encode($values, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (!is_string($json)) {
        $json = '{}';
    }

    $row = [
        'token' => $db->escape_string($token),
        'uid' => (int)($payload['uid'] ?? 0),
        'forum_id' => (int)($payload['forum_id'] ?? 0),
        'mechanic' => $db->escape_string((string)($payload['mechanic'] ?? '')),
        'payload_json' => $db->escape_string($json),
        'created_at' => (int)($payload['created_at'] ?? TIME_NOW),
        'expires_at' => (int)($payload['expires_at'] ?? (TIME_NOW + 900)),
    ];

    $db->delete_query(AF_ATF_TABLE_PREFILL, "token='" . $row['token'] . "'");
    $db->insert_query(AF_ATF_TABLE_PREFILL, $row);
    $db->delete_query(AF_ATF_TABLE_PREFILL, 'expires_at<' . (int)(TIME_NOW - 3600));

    return true;
}

function af_atf_prefill_store_consume(string $token, int $uid, int $fid): array
{
    global $db;

    $token = strtolower(trim($token));
    if (!preg_match('~^[a-f0-9]{16,128}$~', $token) || $uid <= 0 || $fid <= 0 || !is_object($db)) {
        return [];
    }
    if (!$db->table_exists(AF_ATF_TABLE_PREFILL)) {
        return [];
    }

    $row = $db->fetch_array($db->simple_select(
        AF_ATF_TABLE_PREFILL,
        '*',
        "token='" . $db->escape_string($token) . "'",
        ['limit' => 1]
    ));
    if (!$row) {
        return [];
    }

    if ((int)($row['uid'] ?? 0) !== $uid || (int)($row['forum_id'] ?? 0) !== $fid || (int)($row['expires_at'] ?? 0) < TIME_NOW) {
        return [];
    }

    $values = json_decode((string)($row['payload_json'] ?? '{}'), true);
    if (!is_array($values)) {
        $values = [];
    }

    $db->delete_query(AF_ATF_TABLE_PREFILL, "token='" . $db->escape_string($token) . "'");

    return [
        'values' => $values,
        'mechanic' => (string)($row['mechanic'] ?? ''),
    ];
}


function af_advancedthreadfields_deactivate(): void
{
    global $cache;

    af_atf_revert_template_edits();

    if (is_object($cache)) {
        $cache->update('af_atf_tpl_state', ['applied' => 0, 'time' => TIME_NOW]);
    }

    af_atf_rebuild_cache(true);
}

function af_advancedthreadfields_on_enable(): void
{
    global $cache;

    af_atf_install_templates();
    af_atf_apply_template_edits();

    if (is_object($cache)) {
        $cache->update('af_atf_tpl_state', ['applied' => 1, 'time' => TIME_NOW]);
    }

    af_atf_seed_character_contract_fields();
}

function af_advancedthreadfields_on_disable(): void
{
    global $cache;

    af_atf_revert_template_edits();

    if (is_object($cache)) {
        $cache->update('af_atf_tpl_state', ['applied' => 0, 'time' => TIME_NOW]);
    }
}

/* -------------------- INIT / PRE_OUTPUT -------------------- */
function af_advancedthreadfields_init(): void
{
    global $plugins;

    if (!af_atf_is_enabled()) {
        return;
    }

    // ВАЖНО: ранний перехват JSON-эндпоинтов ещё на global_start,
    // чтобы не успевал построиться HTML и не инжектились ассеты.
    // (на текущем хите уже поздно, но на следующем запросе сработает идеально)
    $plugins->add_hook('global_start', 'af_atf_early_ajax_router', -100);

    // гарантируем, что переменные реально вставлены в шаблоны
    af_atf_template_edits_ensure_applied();

    // Ввод/формы
    $plugins->add_hook('newthread_start', 'af_atf_newthread_start');
    $plugins->add_hook('editpost_start', 'af_atf_editpost_start');

    // Сабмит (чтобы гарантировать непустой message даже без JS)
    $plugins->add_hook('newthread_do_newthread_start', 'af_atf_newthread_do_start');
    $plugins->add_hook('editpost_do_editpost_start', 'af_atf_editpost_do_start');

    // финальная страховка сохранения (когда tid уже точно есть)
    $plugins->add_hook('newthread_do_newthread_end', 'af_atf_newthread_do_end');
    $plugins->add_hook('editpost_do_editpost_end', 'af_atf_editpost_do_end');

    // Валидация/сохранение: DataHandler
    $plugins->add_hook('datahandler_post_validate', 'af_atf_dh_validate');
    $plugins->add_hook('datahandler_post_insert_thread', 'af_atf_dh_insert_thread');
    $plugins->add_hook('datahandler_post_update', 'af_atf_dh_update_post');

    // Вывод
    $plugins->add_hook('forumdisplay_start', 'af_atf_forumdisplay_start');
    $plugins->add_hook('showthread_start', 'af_atf_showthread_start');
    $plugins->add_hook('postbit', 'af_atf_postbit');
    $plugins->add_hook('forumdisplay_get_threads', 'af_atf_forumdisplay_get_threads');
    $plugins->add_hook('forumdisplay_thread', 'af_atf_forumdisplay_thread');

    // AJAX/JSON подсказки пользователей (как запасной путь)
    // Ставим отрицательный приоритет — пусть бежит раньше чужих обработчиков misc_start.
    $plugins->add_hook('misc_start', 'af_atf_misc_start', -50);

    // Ассеты
    $plugins->add_hook('pre_output_page', 'af_advancedthreadfields_pre_output', 10);
}

function af_advancedthreadfields_pre_output(&$page = ''): void
{
    if (!af_atf_is_enabled()) {
        return;
    }

    if (!af_atf_is_relevant_script()) {
        return;
    }

    if (stripos($page, 'id="redirect"') !== false || stripos($page, "id='redirect'") !== false) {
        return;
    }

    $assetsDisabled = af_atf_assets_disabled_for_current_page();

    /* -------------------- 1) ASSETS -------------------- */
    if (strpos($page, AF_ATF_MARK) === false && !$assetsDisabled) {
        global $mybb;

        $base = rtrim((string)$mybb->settings['bburl'], '/');
        $css  = $base.'/'.AF_ATF_ASSET_CSS;
        $js   = $base.'/'.AF_ATF_ASSET_JS;

        $extra = '';
        if (!empty($GLOBALS['af_atf_hide_editor'])) {
            $extra .= "\n<meta name=\"af-atf-hide-editor\" content=\"1\" />\n";
        }
        $extra .= "\n<meta name=\"af-atf-kb-endpoint\" content=\"{$base}/misc.php?action=af_kb_get\" />\n";

        $tag = "\n".AF_ATF_MARK
             . "\n<link rel=\"stylesheet\" href=\"{$css}\" />"
             . "\n<script src=\"{$js}\" defer></script>\n"
             . $extra;

        if (stripos($page, '</head>') !== false) {
            $page = preg_replace('~</head>~i', $tag."</head>", $page, 1);
        } elseif (stripos($page, '</body>') !== false) {
            $page = preg_replace('~</body>~i', $tag."</body>", $page, 1);
        } else {
            $page .= $tag;
        }
    }

    /* -------------------- 2) RUNTIME INSERT -------------------- */

    if ((defined('THIS_SCRIPT') && (THIS_SCRIPT === 'newthread.php' || THIS_SCRIPT === 'editpost.php'))
        && !empty($GLOBALS['af_atf_input_html'])
        && strpos($page, AF_ATF_TPL_MARK_INPUT) === false
    ) {
        $insert = "\n" . AF_ATF_TPL_MARK_INPUT . "\n" . $GLOBALS['af_atf_input_html'] . "\n";

        $count = 0;
        $page2 = @preg_replace(
            '~(<input\b[^>]*\bname=(["\'])subject\2[^>]*>\s*</td>)~is',
            '$1' . $insert,
            $page,
            1,
            $count
        );

        if ($count > 0 && is_string($page2)) {
            $page = $page2;
        } else {
            $count = 0;
            $page2 = @preg_replace(
                '~(<input\b[^>]*\bname=(["\'])subject\2[^>]*>)~is',
                '$1' . $insert,
                $page,
                1,
                $count
            );
            if ($count > 0 && is_string($page2)) {
                $page = $page2;
            }
        }
    }

    if ((defined('THIS_SCRIPT') && THIS_SCRIPT === 'showthread.php')
        && !empty($GLOBALS['af_atf_showthread_block'])
        && strpos($page, AF_ATF_TPL_MARK_SHOW) === false
    ) {
        $insert = "\n" . AF_ATF_TPL_MARK_SHOW . "\n" . $GLOBALS['af_atf_showthread_block'] . "\n";

        $count = 0;
        $page2 = @preg_replace(
            '~(<td\b[^>]*\bclass=(["\'])thead\2[^>]*>.*?<strong\b[^>]*>.*?</strong>)~is',
            '$1' . $insert,
            $page,
            1,
            $count
        );

        if ($count > 0 && is_string($page2)) {
            $page = $page2;
        } else {
            $count = 0;
            $page2 = @preg_replace('~(</strong>)~i', '$1' . $insert, $page, 1, $count);
            if ($count > 0 && is_string($page2)) {
                $page = $page2;
            }
        }
    }

    // forumdisplay: вставляем CTA строго перед <!-- start: forumdisplay_newthread -->
    if (defined('THIS_SCRIPT')
        && THIS_SCRIPT === 'forumdisplay.php'
        && strpos($page, '<!--AF_ATF_FORUM_CTA-->') === false
        && af_atf_page_has_newthread_control($page)
    ) {
        $ctaHtml = trim((string)($GLOBALS['af_atf_forum_catalog_cta_html'] ?? ''));

        $forumId = af_atf_resolve_current_forum_id();
        if ($forumId <= 0) {
            $forumId = af_atf_detect_forum_id_from_forumdisplay_html($page);
        }

        if ($ctaHtml === '' && $forumId > 0) {
            $ctaHtml = af_atf_build_catalog_cta_html($forumId);
            if ($ctaHtml !== '') {
                $GLOBALS['af_atf_forum_catalog_cta_html'] = $ctaHtml;
            }
        }

        if ($ctaHtml !== '') {
            $page = af_atf_inject_catalog_cta_near_newthread_button($page, $ctaHtml);
        }
    }

    /* -------------------- 3) PREVIEW INSERT -------------------- */
    if (defined('THIS_SCRIPT')
        && (THIS_SCRIPT === 'newthread.php' || THIS_SCRIPT === 'editpost.php')
        && !empty($GLOBALS['af_atf_preview_html'])
        && strpos($page, '<!--AF_ATF_PREVIEW-->') === false
    ) {
        $insert = "\n<!--AF_ATF_PREVIEW-->\n" . $GLOBALS['af_atf_preview_html'] . "\n";

        $count = 0;
        $page2 = @preg_replace('~(<!--\s*end:\s*previewpost\s*-->)~i', $insert . '$1', $page, 1, $count);
        if ($count > 0 && is_string($page2)) {
            $page = $page2;
            return;
        }

        $count = 0;
        $page2 = @preg_replace('~(<div\b[^>]*(?:id|class)=(["\'])(?:previewpost|post_preview|preview_post)\2[^>]*>)~i', $insert . '$1', $page, 1, $count);
        if ($count > 0 && is_string($page2)) {
            $page = $page2;
            return;
        }

        $count = 0;
        $page2 = @preg_replace('~(<form\b[^>]*>)~i', $insert . '$1', $page, 1, $count);
        if ($count > 0 && is_string($page2)) {
            $page = $page2;
            return;
        }

        $count = 0;
        $page2 = @preg_replace('~(<div\b[^>]*\bid=(["\'])content\2[^>]*>)~i', '$1' . $insert, $page, 1, $count);
        if ($count > 0 && is_string($page2)) {
            $page = $page2;
            return;
        }

        $count = 0;
        $page2 = @preg_replace('~(<body\b[^>]*>)~i', '$1' . $insert, $page, 1, $count);
        if ($count > 0 && is_string($page2)) {
            $page = $page2;
            return;
        }

        $page .= $insert;
    }
}

function af_atf_misc_start(): void
{
    global $mybb;

    if (!af_atf_is_enabled()) {
        return;
    }

    $action = (string)$mybb->get_input('action');

    if ($action === 'af_atf_user_suggest') {
        af_atf_clean_output_buffers();
        af_atf_user_suggest_endpoint(); // die внутри
    }

    if ($action === 'af_atf_user_resolve') {
        af_atf_clean_output_buffers();
        af_atf_user_resolve_endpoint(); // die внутри
    }

    if ($action === 'af_kb_get') {
        af_atf_clean_output_buffers();
        af_atf_kb_get_endpoint(); // die внутри
    }

    if ($action === 'af_atf_kb_race_variants') {
        af_atf_clean_output_buffers();
        af_atf_kb_race_variants_endpoint(); // die внутри
    }

    if ($action === 'af_atf_character_kb_create') {
        af_atf_handle_character_kb_bridge_action(false);
    }

    if ($action === 'af_atf_character_kb_sync') {
        af_atf_handle_character_kb_bridge_action(true);
    }
}

function af_atf_early_ajax_router(): void
{
    global $mybb;

    if (!isset($mybb) || !is_object($mybb)) {
        return;
    }

    if (!defined('THIS_SCRIPT') || THIS_SCRIPT !== 'misc.php') {
        return;
    }

    $action = (string)$mybb->get_input('action');

    // Перехватываем строго наши JSON-эндпоинты
    if ($action === 'af_atf_user_suggest') {
        af_atf_clean_output_buffers();
        af_atf_user_suggest_endpoint(); // внутри будет die
    }

    if ($action === 'af_atf_user_resolve') {
        af_atf_clean_output_buffers();
        af_atf_user_resolve_endpoint(); // внутри будет die
    }

    if ($action === 'af_kb_get') {
        af_atf_clean_output_buffers();
        af_atf_kb_get_endpoint(); // внутри будет die
    }

    if ($action === 'af_atf_kb_race_variants') {
        af_atf_clean_output_buffers();
        af_atf_kb_race_variants_endpoint(); // внутри будет die
    }

    if ($action === 'af_atf_character_kb_create') {
        af_atf_handle_character_kb_bridge_action(false);
    }

    if ($action === 'af_atf_character_kb_sync') {
        af_atf_handle_character_kb_bridge_action(true);
    }
}

function af_atf_clean_output_buffers(): void
{
    // На AJAX-эндпоинтах нам НЕ нужен никакой HTML/обёртки.
    // Если что-то успело попасть в буфер — вычищаем полностью.
    while (ob_get_level() > 0) {
        @ob_end_clean();
    }
}

function af_atf_json_response($data, int $status = 200): void
{
    // На всякий: если кто-то уже начал буферить/печатать — убираем мусор
    af_atf_clean_output_buffers();

    if (!headers_sent()) {
        header('Content-Type: application/json; charset=utf-8', true, $status);
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Pragma: no-cache');
    }

    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    die; // <-- критично: обрываем MyBB, чтобы он не дорисовал HTML
}

function af_atf_user_suggest_endpoint(): void
{
    global $db, $mybb;

    // гостям обычно не надо автокомплит
    if (empty($mybb->user['uid'])) {
        af_atf_json_response(['ok' => 0, 'items' => []], 403);
        return;
    }

    $q = trim((string)$mybb->get_input('query'));
    if ($q === '') {
        af_atf_json_response(['ok' => 1, 'items' => []]);
        return;
    }

    if (my_strlen($q) > 64) {
        $q = my_substr($q, 0, 64);
    }

    $like = $db->escape_string($q);
    $like = str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $like);

    $items = [];
    $seen  = [];

    // 1) сначала префикс (gam%)
    $res1 = $db->simple_select(
        'users',
        'uid,username',
        "username LIKE '{$like}%' ESCAPE '\\\\'",
        ['order_by' => 'username', 'order_dir' => 'ASC', 'limit' => 10]
    );

    while ($u = $db->fetch_array($res1)) {
        $uid = (int)$u['uid'];
        if ($uid <= 0 || isset($seen[$uid])) {
            continue;
        }
        $seen[$uid] = true;
        $items[] = [
            'uid' => $uid,
            'username' => (string)$u['username'],
        ];
        if (count($items) >= 10) {
            break;
        }
    }

    // 2) потом “содержит” (%gam%), если ещё есть место
    if (count($items) < 10) {
        $need = 10 - count($items);

        $res2 = $db->simple_select(
            'users',
            'uid,username',
            "username LIKE '%{$like}%' ESCAPE '\\\\'",
            ['order_by' => 'username', 'order_dir' => 'ASC', 'limit' => 20]
        );

        while ($u = $db->fetch_array($res2)) {
            $uid = (int)$u['uid'];
            if ($uid <= 0 || isset($seen[$uid])) {
                continue;
            }
            $seen[$uid] = true;
            $items[] = [
                'uid' => $uid,
                'username' => (string)$u['username'],
            ];
            if (count($items) >= 10) {
                break;
            }
        }
    }

    af_atf_json_response(['ok' => 1, 'items' => $items]);
}

function af_atf_user_resolve_endpoint(): void
{
    global $db, $mybb;

    if (empty($mybb->user['uid'])) {
        af_atf_json_response(['ok' => 0, 'items' => []], 403);
        return;
    }

    $raw = trim((string)$mybb->get_input('uids'));
    if ($raw === '') {
        af_atf_json_response(['ok' => 1, 'items' => []]);
        return;
    }

    $uids = array_filter(array_map('intval', preg_split('~\s*,\s*~', $raw)));
    $uids = array_values(array_unique(array_filter($uids, static fn($x) => $x > 0)));

    if (empty($uids)) {
        af_atf_json_response(['ok' => 1, 'items' => []]);
        return;
    }

    // лимит на всякий
    if (count($uids) > 50) {
        $uids = array_slice($uids, 0, 50);
    }

    $in = implode(',', array_map('intval', $uids));

    $map = [];
    $res = $db->simple_select('users', 'uid,username', "uid IN ({$in})");
    while ($u = $db->fetch_array($res)) {
        $map[(int)$u['uid']] = (string)$u['username'];
    }

    // вернуть в том же порядке, что пришло
    $items = [];
    foreach ($uids as $uid) {
        if (isset($map[$uid])) {
            $items[] = ['uid' => $uid, 'username' => $map[$uid]];
        }
    }

    af_atf_json_response(['ok' => 1, 'items' => $items]);
}

function af_atf_kb_get_endpoint(): void
{
    global $mybb, $db;

    $requestedType = strtolower(trim((string)$mybb->get_input('type')));
    $type = function_exists('af_kb_resolve_requested_type')
        ? af_kb_resolve_requested_type($requestedType)
        : $requestedType;
    $key = strtolower(trim((string)$mybb->get_input('key')));

    if (!preg_match('/^[a-z0-9_]{2,64}$/', $type)) {
        af_atf_json_response(['ok' => 0, 'error' => 'invalid_type'], 400);
        return;
    }

    if (!preg_match('/^[a-z0-9_-]{2,64}$/i', $key)) {
        af_atf_json_response(['ok' => 0, 'error' => 'invalid_key'], 400);
        return;
    }

    // KB должен решать доступ сам.
    // Не режем здесь доступ через forum-permission вроде canviewthreads:
    // это отдельная сущность и она ломает просмотр KB обычным пользователям.
    if (function_exists('af_kb_can_view') && !af_kb_can_view()) {
        af_atf_json_response(['ok' => 0, 'error' => 'no_access'], 403);
        return;
    }

    if (!is_object($db) || !$db->table_exists('af_kb_entries')) {
        af_atf_json_response(['ok' => 0, 'error' => 'not_found'], 404);
        return;
    }

    $where = "type='".$db->escape_string($type)."' AND `key`='".$db->escape_string($key)."'";
    if (!function_exists('af_kb_can_edit') || !af_kb_can_edit()) {
        $where .= ' AND active=1';
    }

    $row = $db->fetch_array($db->simple_select(
        'af_kb_entries',
        '*',
        $where,
        ['limit' => 1]
    ));

    if (!is_array($row) || empty($row)) {
        af_atf_json_response(['ok' => 0, 'error' => 'not_found'], 404);
        return;
    }

    if (function_exists('af_kb_entry_visible_in_context') && function_exists('af_kb_can_edit')) {
        if (!af_kb_entry_visible_in_context($row, 'catalog', af_kb_can_edit())) {
            af_atf_json_response(['ok' => 0, 'error' => 'not_found'], 404);
            return;
        }
    }

    $canEdit = function_exists('af_kb_can_edit') && af_kb_can_edit();
    $entryPayload = function_exists('af_kb_build_entry_modal_payload')
        ? af_kb_build_entry_modal_payload($row, $canEdit)
        : [
            'type' => $type,
            'key' => $key,
            'title' => af_atf_kb_pick_text($row, 'title'),
            'short_html' => '',
            'body_html' => '',
            'sections_html' => [],
            'blocks' => [],
        ];

    af_atf_json_response([
        'ok' => 1,
        'entry' => $entryPayload,
    ]);
}
function af_atf_kb_race_variants_endpoint(): void
{
    global $mybb;

    $raceKey = strtolower(trim((string)$mybb->get_input('race')));
    if ($raceKey === '') {
        $raceKey = strtolower(trim((string)$mybb->get_input('race_key')));
    }

    if (!preg_match('/^[a-z0-9_-]{2,64}$/i', $raceKey)) {
        af_atf_json_response(['ok' => 0, 'error' => 'invalid_race'], 400);
        return;
    }

    $activeOnly = (int)$mybb->get_input('include_inactive', MyBB::INPUT_INT) !== 1;

    if (function_exists('af_kb_get_race_variants')) {
        $rows = af_kb_get_race_variants($raceKey, $activeOnly);
    } else {
        $rows = [];
    }

    $items = [];
    foreach ($rows as $row) {
        $entry = is_array($row['variant'] ?? null) ? $row['variant'] : [];
        if (empty($entry)) {
            continue;
        }

        $label = af_atf_kb_pick_text($entry, 'title');
        $value = (string)($entry['key'] ?? '');
        if ($value === '') {
            continue;
        }
        if ($label === '') {
            $label = $value;
        }

        $items[] = [
            'value' => $value,
            'label' => $label,
            'sortorder' => (int)($row['sortorder'] ?? 0),
        ];
    }

    af_atf_json_response([
        'ok' => 1,
        'race' => $raceKey,
        'items' => $items,
        'select_contract' => [
            'value_key' => 'value',
            'label_key' => 'label',
        ],
    ]);
}

/* -------------------- DB UPGRADE HELPERS -------------------- */
function af_atf_db_ensure_columns(): void
{
    global $db;

    if (!$db->table_exists(AF_ATF_TABLE_FIELDS)) {
        return;
    }

    $cols = [
        'groupid'      => "ALTER TABLE `".TABLE_PREFIX.AF_ATF_TABLE_FIELDS."` ADD `groupid` INT UNSIGNED NOT NULL DEFAULT 0 AFTER `fieldid`",
        'allow_html'   => "ALTER TABLE `".TABLE_PREFIX.AF_ATF_TABLE_FIELDS."` ADD `allow_html` TINYINT(1) NOT NULL DEFAULT 0",
        'parse_mycode' => "ALTER TABLE `".TABLE_PREFIX.AF_ATF_TABLE_FIELDS."` ADD `parse_mycode` TINYINT(1) NOT NULL DEFAULT 1",
        'parse_smilies'=> "ALTER TABLE `".TABLE_PREFIX.AF_ATF_TABLE_FIELDS."` ADD `parse_smilies` TINYINT(1) NOT NULL DEFAULT 1",
    ];

    foreach ($cols as $col => $sql) {
        if (method_exists($db, 'field_exists')) {
            if (!$db->field_exists($col, AF_ATF_TABLE_FIELDS)) {
                $db->write_query($sql);
            }
        }
    }
}

function af_atf_db_ensure_group_columns(): void
{
    global $db;

    if (!$db->table_exists(AF_ATF_TABLE_GROUPS)) {
        return;
    }

    $cols = [
        'character_mechanic_mode' => "ALTER TABLE `".TABLE_PREFIX.AF_ATF_TABLE_GROUPS."` ADD `character_mechanic_mode` VARCHAR(16) NOT NULL DEFAULT 'auto' AFTER `forums`",
        'catalog_characters_url'   => "ALTER TABLE `".TABLE_PREFIX.AF_ATF_TABLE_GROUPS."` ADD `catalog_characters_url` VARCHAR(500) NOT NULL DEFAULT '' AFTER `forums`",
        'catalog_roles_url'        => "ALTER TABLE `".TABLE_PREFIX.AF_ATF_TABLE_GROUPS."` ADD `catalog_roles_url` VARCHAR(500) NOT NULL DEFAULT '' AFTER `catalog_characters_url`",
        'catalog_characters_label' => "ALTER TABLE `".TABLE_PREFIX.AF_ATF_TABLE_GROUPS."` ADD `catalog_characters_label` VARCHAR(255) NOT NULL DEFAULT '' AFTER `catalog_roles_url`",
        'catalog_roles_label'      => "ALTER TABLE `".TABLE_PREFIX.AF_ATF_TABLE_GROUPS."` ADD `catalog_roles_label` VARCHAR(255) NOT NULL DEFAULT '' AFTER `catalog_characters_label`",
        'show_catalog_cta'         => "ALTER TABLE `".TABLE_PREFIX.AF_ATF_TABLE_GROUPS."` ADD `show_catalog_cta` TINYINT(1) NOT NULL DEFAULT 0 AFTER `catalog_roles_label`",
    ];

    foreach ($cols as $col => $sql) {
        if (method_exists($db, 'field_exists') && !$db->field_exists($col, AF_ATF_TABLE_GROUPS)) {
            $db->write_query($sql);
        }
    }
}

function af_atf_group_cache_select_fields(): array
{
    global $db;

    // Базовые колонки старой таблицы — считаем обязательными
    $fields = ['gid', 'title', 'forums', 'active', 'sortorder'];

    // Новые колонки из Prompt 2 — могут ещё отсутствовать на старой БД
    $optional = [
        'character_mechanic_mode',
        'catalog_characters_url',
        'catalog_roles_url',
        'catalog_characters_label',
        'catalog_roles_label',
        'show_catalog_cta',
    ];

    if (!is_object($db) || !$db->table_exists(AF_ATF_TABLE_GROUPS)) {
        return $fields;
    }

    // Если field_exists доступен — выбираем только реально существующие колонки
    if (method_exists($db, 'field_exists')) {
        foreach ($optional as $col) {
            if ($db->field_exists($col, AF_ATF_TABLE_GROUPS)) {
                $fields[] = $col;
            }
        }
        return array_values(array_unique($fields));
    }

    // fallback: если field_exists вдруг недоступен, пробуем старый + новый набор
    return array_values(array_unique(array_merge($fields, $optional)));
}

function af_atf_tpl_rebuild_all_caches(): void
{
    global $db;

    af_atf_tpl_ensure_admin_template_funcs();

    if (!function_exists('cache_templates') || !is_object($db)) {
        return;
    }

    // Пересобираем cache для всех sid>0 и пробуем для -1 (если поддерживается)
    foreach (af_atf_tpl_get_target_sids() as $sid) {
        $sid = (int)$sid;

        // cache_templates(-1) в некоторых сборках есть, в некоторых — нет/не нужно.
        // Поэтому просто пробуем без фанатизма.
        try {
            cache_templates($sid);
        } catch (Throwable $e) {
            // молча игнорируем
        }
    }
}

/* -------------------- HELPERS: ENABLE / CONTEXT -------------------- */

function af_atf_is_enabled(): bool
{
    global $mybb;
    return !empty($mybb->settings['af_advancedthreadfields_enabled']);
}

function af_atf_parse_assets_blacklist(string $raw): array
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
            $query = trim(substr($line, $qPos + 1));
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
                    $v = trim(substr($part, $eqPos + 1));
                    if ($k === 'action') {
                        $action = strtolower($v);
                        break;
                    }
                }
            }
        }

        if ($script === '') {
            continue;
        }

        $out[] = ['script' => $script, 'action' => $action];
    }

    return $out;
}

function af_atf_resolve_current_forum_id(): int
{
    global $fid, $foruminfo, $thread, $mybb;

    $forumId = 0;

    if (!empty($fid)) {
        $forumId = (int)$fid;
    }

    if ($forumId <= 0 && is_array($foruminfo)) {
        $forumId = (int)($foruminfo['fid'] ?? 0);
    }

    if ($forumId <= 0 && is_array($thread)) {
        $forumId = (int)($thread['fid'] ?? 0);
    }

    if ($forumId <= 0 && isset($mybb) && is_object($mybb)) {
        $forumId = (int)$mybb->get_input('fid', MyBB::INPUT_INT);
    }

    if ($forumId <= 0 && isset($_GET['fid'])) {
        $forumId = (int)$_GET['fid'];
    }

    return $forumId > 0 ? $forumId : 0;
}

function af_atf_sort_catalog_groups(array &$groups): void
{
    usort($groups, static function (array $a, array $b): int {
        $sa = (int)($a['sortorder'] ?? 0);
        $sb = (int)($b['sortorder'] ?? 0);

        if ($sa === $sb) {
            return ((int)($a['gid'] ?? 0)) <=> ((int)($b['gid'] ?? 0));
        }

        return $sa <=> $sb;
    });
}

function af_atf_get_catalog_group_for_forum_live(int $fid): ?array
{
    global $db;

    if ($fid <= 0 || !is_object($db) || !$db->table_exists(AF_ATF_TABLE_GROUPS)) {
        return null;
    }

    $selectFields = implode(',', af_atf_group_cache_select_fields());

    $q = $db->simple_select(
        AF_ATF_TABLE_GROUPS,
        $selectFields,
        'active=1',
        ['order_by' => 'sortorder', 'order_dir' => 'ASC']
    );

    $explicitMatches = [];
    $globalMatches = [];

    while ($g = $db->fetch_array($q)) {
        $showCatalogCta = (int)($g['show_catalog_cta'] ?? 0);
        if ($showCatalogCta !== 1) {
            continue;
        }

        $charUrl = trim((string)($g['catalog_characters_url'] ?? ''));
        $rolesUrl = trim((string)($g['catalog_roles_url'] ?? ''));

        if ($charUrl === '' && $rolesUrl === '') {
            continue;
        }

        $forumsRaw = trim((string)($g['forums'] ?? ''));
        $forumsSet = af_atf_forums_csv_to_set($forumsRaw);

        $group = [
            'gid' => (int)($g['gid'] ?? 0),
            'title' => (string)($g['title'] ?? ''),
            'forums_raw' => $forumsRaw,
            'forums_set' => $forumsSet,
            'active' => (int)($g['active'] ?? 0),
            'sortorder' => (int)($g['sortorder'] ?? 0),
            'character_mechanic_mode' => (string)($g['character_mechanic_mode'] ?? 'auto'),
            'catalog_characters_url' => $charUrl,
            'catalog_roles_url' => $rolesUrl,
            'catalog_characters_label' => (string)($g['catalog_characters_label'] ?? ''),
            'catalog_roles_label' => (string)($g['catalog_roles_label'] ?? ''),
            'show_catalog_cta' => $showCatalogCta,
        ];

        if (!empty($forumsSet)) {
            if (isset($forumsSet[$fid])) {
                $explicitMatches[] = $group;
            }
            continue;
        }

        $globalMatches[] = $group;
    }

    if (!empty($explicitMatches)) {
        af_atf_sort_catalog_groups($explicitMatches);
        return $explicitMatches[0];
    }

    if (!empty($globalMatches)) {
        af_atf_sort_catalog_groups($globalMatches);
        return $globalMatches[0];
    }

    return null;
}

function af_atf_catalog_flag_enabled($value): bool
{
    if (is_bool($value)) {
        return $value;
    }

    if (is_int($value) || is_float($value)) {
        return ((int)$value) === 1;
    }

    $value = strtolower(trim((string)$value));
    return in_array($value, ['1', 'yes', 'true', 'on'], true);
}

function af_atf_detect_forum_id_from_forumdisplay_html(string $page): int
{
    if ($page === '') {
        return 0;
    }

    $decoded = html_entity_decode($page, ENT_QUOTES, 'UTF-8');

    $patterns = [
        '~newthread\.php\?fid=(\d+)~i',
        '~newthread-(\d+)\.html~i',
        '~forumdisplay\.php\?fid=(\d+)~i',
        '~forum-(\d+)\.html~i',
    ];

    foreach ($patterns as $pattern) {
        if (preg_match($pattern, $decoded, $m)) {
            $fid = (int)($m[1] ?? 0);
            if ($fid > 0) {
                return $fid;
            }
        }
    }

    return 0;
}

function af_atf_page_has_newthread_control(string $page): bool
{
    if ($page === '') {
        return false;
    }

    if (stripos($page, 'forumdisplay_newthread') !== false) {
        return true;
    }

    if (preg_match('~(?:newthread(?:\.php|\-\d+\.html)|action=newthread)~i', $page)) {
        return true;
    }

    if (preg_match('~создать\s+тему~iu', $page)) {
        return true;
    }

    return false;
}

function af_atf_inject_catalog_cta_near_newthread_button(string $page, string $ctaHtml): string
{
    $ctaHtml = trim($ctaHtml);
    if ($ctaHtml === '') {
        return $page;
    }

    if (strpos($page, '<!--AF_ATF_FORUM_CTA-->') !== false) {
        return $page;
    }

    $insert = "\n<!--AF_ATF_FORUM_CTA-->\n" . $ctaHtml . "\n";

    // 1) ЖЁСТКИЙ КАНОНИЧНЫЙ ЯКОРЬ:
    // вставляем РОВНО перед <!-- start: forumdisplay_newthread -->
    $needle = '<!-- start: forumdisplay_newthread -->';
    $pos = strpos($page, $needle);
    if ($pos !== false) {
        return substr($page, 0, $pos) . $insert . substr($page, $pos);
    }

    // 2) Fallback: если комментарий в теме убран, но есть ссылка newthread внутри float_right
    if (preg_match('~<div\b[^>]*class=(["\'])[^"\']*\bfloat_right\b[^"\']*\1[^>]*>~i', $page, $m, PREG_OFFSET_CAPTURE)) {
        $blockStart = (int)$m[0][1];
        $blockHtml = substr($page, $blockStart, 2500);

        if ($blockHtml !== false && preg_match('~<a\b[^>]*href=(["\'])[^"\']*newthread\.php\?fid=\d+[^"\']*\1[^>]*>~i', $blockHtml, $m2, PREG_OFFSET_CAPTURE)) {
            $relativePos = (int)$m2[0][1];
            $absolutePos = $blockStart + $relativePos;
            return substr($page, 0, $absolutePos) . $insert . substr($page, $absolutePos);
        }
    }

    // 3) Последний fallback: перед любой ссылкой newthread на странице
    if (preg_match('~<a\b[^>]*href=(["\'])[^"\']*newthread\.php\?fid=\d+[^"\']*\1[^>]*>~i', $page, $m3, PREG_OFFSET_CAPTURE)) {
        $pos = (int)$m3[0][1];
        return substr($page, 0, $pos) . $insert . substr($page, $pos);
    }

    return $page;
}


function af_atf_seed_character_contract_fields(): void
{
    global $db;

    if (!is_object($db) || !$db->table_exists(AF_ATF_TABLE_FIELDS) || !$db->table_exists(AF_ATF_TABLE_GROUPS)) {
        return;
    }

    $groupId = 0;
    $legacyGroup = $db->fetch_array($db->simple_select(AF_ATF_TABLE_GROUPS, 'gid', "title='CTA'", ['limit' => 1]));
    if (!empty($legacyGroup['gid'])) {
        $groupId = (int)$legacyGroup['gid'];
    }

    if ($groupId <= 0) {
        $existingCharacterField = $db->fetch_array($db->simple_select(AF_ATF_TABLE_FIELDS, 'groupid', "name='character_name'", ['limit' => 1]));
        if (!empty($existingCharacterField['groupid'])) {
            $groupId = (int)$existingCharacterField['groupid'];
        }
    }

    if ($groupId <= 0) {
        $groupId = (int)$db->insert_query(AF_ATF_TABLE_GROUPS, [
            'title' => $db->escape_string('CTA'),
            'description' => $db->escape_string('Character contract fields (auto-seeded)'),
            'forums' => '',
            'character_mechanic_mode' => $db->escape_string('auto'),
            'catalog_characters_url' => '',
            'catalog_roles_url' => '',
            'catalog_characters_label' => $db->escape_string('Посмотреть канонов'),
            'catalog_roles_label' => $db->escape_string('Посмотреть списки ролей'),
            'show_catalog_cta' => 0,
            'active' => 1,
            'sortorder' => 0,
        ]);
    }

    if ($groupId <= 0) {
        return;
    }

    $definitions = [
        ['name' => 'character_pic', 'title' => 'Character picture', 'type' => 'image', 'sortorder' => 10, 'maxlen' => 500],
        ['name' => 'character_prototype', 'title' => 'Prototype', 'type' => 'text', 'sortorder' => 20, 'maxlen' => 255],
        ['name' => 'character_name', 'title' => 'Name (EN)', 'type' => 'text', 'sortorder' => 30, 'maxlen' => 255],
        ['name' => 'character_name_ru', 'title' => 'Name (RU)', 'type' => 'text', 'sortorder' => 40, 'maxlen' => 255],
        ['name' => 'character_nicknames', 'title' => 'Nicknames', 'type' => 'text', 'sortorder' => 50, 'maxlen' => 500],
        ['name' => 'character_element', 'title' => 'Element', 'type' => 'kb_dynamic', 'sortorder' => 60, 'options' => "provider=character_element\nmechanic=arpg", 'maxlen' => 128],
        ['name' => 'character_gen', 'title' => 'Gender', 'type' => 'select', 'sortorder' => 70, 'options' => "male=Male\nfemale=Female\nnonbinary=Non-binary\nother=Other", 'maxlen' => 64],
        ['name' => 'character_race', 'title' => 'Race / Origin', 'type' => 'kb_mechanic', 'sortorder' => 80, 'options' => "provider=character_race", 'maxlen' => 128],
        ['name' => 'character_class', 'title' => 'Class / Archetype', 'type' => 'kb_mechanic', 'sortorder' => 90, 'options' => "provider=character_class", 'maxlen' => 128],
        ['name' => 'character_faction', 'title' => 'Faction', 'type' => 'kb_dynamic', 'sortorder' => 100, 'options' => "provider=character_faction\nmechanic=arpg", 'maxlen' => 128],
        ['name' => 'character_app', 'title' => 'Appearance', 'type' => 'textarea', 'sortorder' => 110],
        ['name' => 'character_hp', 'title' => 'HP', 'type' => 'number', 'sortorder' => 200, 'maxlen' => 11],
        ['name' => 'character_defense', 'title' => 'Defense', 'type' => 'number', 'sortorder' => 210, 'maxlen' => 11],
        ['name' => 'character_element_damage_bonus', 'title' => 'Element damage bonus', 'type' => 'number', 'sortorder' => 220, 'maxlen' => 11],
        ['name' => 'character_crit_damage', 'title' => 'Crit damage', 'type' => 'number', 'sortorder' => 230, 'maxlen' => 11],
        ['name' => 'character_healing_received_bonus', 'title' => 'Healing received bonus', 'type' => 'number', 'sortorder' => 240, 'maxlen' => 11],
        ['name' => 'character_attack_power', 'title' => 'Attack power', 'type' => 'number', 'sortorder' => 250, 'maxlen' => 11],
        ['name' => 'character_elemental_mastery', 'title' => 'Elemental mastery', 'type' => 'number', 'sortorder' => 260, 'maxlen' => 11],
        ['name' => 'character_healing_bonus', 'title' => 'Healing bonus', 'type' => 'number', 'sortorder' => 270, 'maxlen' => 11],
        ['name' => 'character_shield_strength', 'title' => 'Shield strength', 'type' => 'number', 'sortorder' => 280, 'maxlen' => 11],
        ['name' => 'character_luck', 'title' => 'Luck', 'type' => 'number', 'sortorder' => 290, 'maxlen' => 11],
        ['name' => 'character_abilities', 'title' => 'Abilities', 'type' => 'character_abilities', 'sortorder' => 300, 'maxlen' => 0],
    ];

    foreach ($definitions as $def) {
        $name = (string)$def['name'];
        $existing = $db->fetch_array($db->simple_select(AF_ATF_TABLE_FIELDS, 'fieldid', "name='" . $db->escape_string($name) . "'", ['limit' => 1]));

        $payload = [
            'groupid' => $groupId,
            'name' => $db->escape_string($name),
            'title' => $db->escape_string((string)$def['title']),
            'description' => $db->escape_string((string)($def['description'] ?? '')),
            'type' => $db->escape_string((string)$def['type']),
            'options' => $db->escape_string((string)($def['options'] ?? '')),
            'required' => (int)($def['required'] ?? 0),
            'active' => 1,
            'show_thread' => 0,
            'show_forum' => 0,
            'sortorder' => (int)($def['sortorder'] ?? 0),
            'maxlen' => (int)($def['maxlen'] ?? 0),
            'regex' => $db->escape_string((string)($def['regex'] ?? '')),
            'format' => $db->escape_string((string)($def['format'] ?? '')),
            'allow_html' => 0,
            'parse_mycode' => 1,
            'parse_smilies' => 0,
        ];

        if (empty($existing['fieldid'])) {
            $db->insert_query(AF_ATF_TABLE_FIELDS, $payload);
        }
    }
}

function af_atf_assets_disabled_for_current_page(): bool
{
    global $mybb;

    $script = defined('THIS_SCRIPT') ? strtolower((string)THIS_SCRIPT) : '';
    if ($script !== '') {
        $script = strtolower(basename(str_replace('\\', '/', $script)));
    }

    $action = '';
    if (isset($mybb) && is_object($mybb)) {
        $action = strtolower((string)($mybb->input['action'] ?? ''));
    }

    // KB и AF KB-эндпоинт никогда не должны глушиться blacklist'ом,
    // иначе ломается modal/open flow и рендер в iframe.
    if ($script === 'kb.php') {
        return false;
    }

    if ($script === 'misc.php' && in_array($action, ['kb', 'af_kb_get'], true)) {
        return false;
    }

    if ($script === '') {
        return false;
    }

    if (function_exists('af_is_blacklisted')
        && af_is_blacklisted(AF_ATF_ID, defined('THIS_SCRIPT') ? (string)THIS_SCRIPT : '')
    ) {
        return true;
    }

    $blacklistRaw = (string)($mybb->settings['af_atf_assets_blacklist'] ?? '');
    $conditions = af_atf_parse_assets_blacklist($blacklistRaw);

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

function af_atf_is_relevant_script(): bool
{
    $script = defined('THIS_SCRIPT') ? strtolower((string)THIS_SCRIPT) : '';
    if ($script !== '') {
        $script = strtolower(basename(str_replace('\\', '/', $script)));
    }

    if (in_array($script, ['newthread.php', 'editpost.php', 'showthread.php', 'forumdisplay.php', 'kb.php'], true)) {
        return true;
    }

    // fallback для кастомных маршрутов/алиасов, которые всё равно рендерят форум
    if (af_atf_resolve_current_forum_id() > 0) {
        return true;
    }

    return false;
}

function af_atf_forum_allowed(array $field, int $fid): bool
{
    $fid = (int)$fid;
    if ($fid <= 0) {
        return false;
    }

    // Новый быстрый путь (из кэша): forums_set = [fid => 1, ...]
    if (isset($field['forums_set']) && is_array($field['forums_set'])) {
        return empty($field['forums_set']) ? true : isset($field['forums_set'][$fid]);
    }

    // Старый фоллбек (если кэш ещё не пересобран)
    $forums = trim((string)($field['forums'] ?? ''));
    if ($forums === '') {
        return true;
    }

    $list = array_filter(array_map('intval', preg_split('~\s*,\s*~', $forums)));
    return in_array($fid, $list, true);
}

function af_atf_get_forums_cache_map(): array
{
    global $cache;

    if (!is_object($cache)) {
        return [];
    }

    $fc = $cache->read('forums');

    // В разных сборках структура может отличаться:
    // - либо ['forums' => [fid => row]]
    // - либо сразу [fid => row]
    if (is_array($fc) && isset($fc['forums']) && is_array($fc['forums'])) {
        return $fc['forums'];
    }

    return is_array($fc) ? $fc : [];
}

function af_atf_expand_forum_ids_with_children(array $selectedFids): array
{
    $selectedFids = array_values(array_unique(array_filter(array_map('intval', $selectedFids), static fn($x) => $x > 0)));
    if (empty($selectedFids)) {
        return [];
    }

    $forums = af_atf_get_forums_cache_map();
    if (empty($forums)) {
        // Если по какой-то причине кэш форумов недоступен — возвращаем как есть
        return $selectedFids;
    }

    // строим pid -> children[fid...]
    $children = [];
    foreach ($forums as $row) {
        $fid = (int)($row['fid'] ?? 0);
        $pid = (int)($row['pid'] ?? 0);
        if ($fid <= 0) {
            continue;
        }
        if (!isset($children[$pid])) {
            $children[$pid] = [];
        }
        $children[$pid][] = $fid;
    }

    $out = [];
    $seen = [];

    $stack = $selectedFids;
    while (!empty($stack)) {
        $cur = (int)array_pop($stack);
        if ($cur <= 0 || isset($seen[$cur])) {
            continue;
        }
        $seen[$cur] = true;
        $out[] = $cur;

        if (!empty($children[$cur])) {
            foreach ($children[$cur] as $ch) {
                $ch = (int)$ch;
                if ($ch > 0 && !isset($seen[$ch])) {
                    $stack[] = $ch;
                }
            }
        }
    }

    sort($out, SORT_NUMERIC);
    return $out;
}

function af_atf_forums_csv_to_set(string $forumsCsv): array
{
    $forumsCsv = trim($forumsCsv);
    if ($forumsCsv === '') {
        return []; // пусто = "All"
    }

    $ids = array_filter(array_map('intval', preg_split('~\s*,\s*~', $forumsCsv)));
    $ids = af_atf_expand_forum_ids_with_children($ids);

    $set = [];
    foreach ($ids as $fid) {
        $fid = (int)$fid;
        if ($fid > 0) {
            $set[$fid] = 1;
        }
    }
    return $set;
}

function af_atf_get_fields_for_forum(int $fid): array
{
    $fields = af_atf_get_fields_cached();
    $out = [];

    foreach ($fields as $f) {
        // группа должна быть активна
        if (empty($f['group_active']) || (int)$f['group_active'] !== 1) {
            continue;
        }
        if ((int)$f['active'] !== 1) {
            continue;
        }
        if (!af_atf_forum_allowed($f, $fid)) {
            continue;
        }
        $out[] = $f;
    }

    usort($out, static function($a, $b) {
        return ((int)$a['sortorder'] <=> (int)$b['sortorder']);
    });

    return $out;
}

function af_atf_get_groups_cached(): array
{
    global $cache;

    $c = $cache->read('af_atf_fields');
    if (!is_array($c) || !isset($c['groups']) || !is_array($c['groups'])) {
        af_atf_rebuild_cache(true);
        $c = $cache->read('af_atf_fields');
    }

    return (is_array($c) && isset($c['groups']) && is_array($c['groups'])) ? $c['groups'] : [];
}
function af_atf_get_catalog_groups_for_forum(int $fid): array
{
    if ($fid <= 0) {
        return [];
    }

    $groups = af_atf_get_groups_cached();
    if (empty($groups)) {
        return [];
    }

    $out = [];

    foreach ($groups as $group) {
        if ((int)($group['active'] ?? 0) !== 1) {
            continue;
        }

        if ((int)($group['show_catalog_cta'] ?? 0) !== 1) {
            continue;
        }

        $forumsSet = is_array($group['forums_set'] ?? null) ? $group['forums_set'] : [];
        if (!empty($forumsSet) && !isset($forumsSet[$fid])) {
            continue;
        }

        $charUrl  = trim((string)($group['catalog_characters_url'] ?? ''));
        $rolesUrl = trim((string)($group['catalog_roles_url'] ?? ''));

        // Пустые CTA нам не нужны
        if ($charUrl === '' && $rolesUrl === '') {
            continue;
        }

        $out[] = $group;
    }

    return $out;
}

function af_atf_build_catalog_cta_html(int $fid): string
{
    global $db;

    $fid = (int)$fid;
    if ($fid <= 0) {
        return '';
    }

    $group = null;

    if (is_object($db) && $db->table_exists(AF_ATF_TABLE_GROUPS)) {
        $selectFields = implode(',', af_atf_group_cache_select_fields());

        $q = $db->simple_select(
            AF_ATF_TABLE_GROUPS,
            $selectFields,
            '',
            ['order_by' => 'sortorder', 'order_dir' => 'ASC']
        );

        $explicitMatches = [];
        $globalMatches = [];

        while ($row = $db->fetch_array($q)) {
            if (!af_atf_catalog_flag_enabled($row['active'] ?? 0)) {
                continue;
            }

            if (!af_atf_catalog_flag_enabled($row['show_catalog_cta'] ?? 0)) {
                continue;
            }

            $charUrl  = trim((string)($row['catalog_characters_url'] ?? ''));
            $rolesUrl = trim((string)($row['catalog_roles_url'] ?? ''));

            if ($charUrl === '' && $rolesUrl === '') {
                continue;
            }

            $forumsRaw = trim((string)($row['forums'] ?? ''));
            $forumsSet = af_atf_forums_csv_to_set($forumsRaw);

            $candidate = [
                'gid' => (int)($row['gid'] ?? 0),
                'title' => (string)($row['title'] ?? ''),
                'forums_raw' => $forumsRaw,
                'forums_set' => $forumsSet,
                'active' => (int)($row['active'] ?? 0),
                'sortorder' => (int)($row['sortorder'] ?? 0),
                'catalog_characters_url' => $charUrl,
                'catalog_roles_url' => $rolesUrl,
                'catalog_characters_label' => (string)($row['catalog_characters_label'] ?? ''),
                'catalog_roles_label' => (string)($row['catalog_roles_label'] ?? ''),
                'show_catalog_cta' => (int)($row['show_catalog_cta'] ?? 0),
            ];

            if (!empty($forumsSet)) {
                if (isset($forumsSet[$fid])) {
                    $explicitMatches[] = $candidate;
                }
            } else {
                $globalMatches[] = $candidate;
            }
        }

        $sortGroups = static function (array &$items): void {
            usort($items, static function (array $a, array $b): int {
                $sa = (int)($a['sortorder'] ?? 0);
                $sb = (int)($b['sortorder'] ?? 0);

                if ($sa === $sb) {
                    return ((int)($a['gid'] ?? 0)) <=> ((int)($b['gid'] ?? 0));
                }

                return $sa <=> $sb;
            });
        };

        if (!empty($explicitMatches)) {
            $sortGroups($explicitMatches);
            $group = $explicitMatches[0];
        } elseif (!empty($globalMatches)) {
            $sortGroups($globalMatches);
            $group = $globalMatches[0];
        }
    }

    if (!$group && function_exists('af_atf_get_groups_cached')) {
        $groups = af_atf_get_groups_cached();

        if (is_array($groups) && !empty($groups)) {
            $explicitMatches = [];
            $globalMatches = [];

            foreach ($groups as $row) {
                if (!af_atf_catalog_flag_enabled($row['active'] ?? 0)) {
                    continue;
                }

                if (!af_atf_catalog_flag_enabled($row['show_catalog_cta'] ?? 0)) {
                    continue;
                }

                $charUrl  = trim((string)($row['catalog_characters_url'] ?? ''));
                $rolesUrl = trim((string)($row['catalog_roles_url'] ?? ''));

                if ($charUrl === '' && $rolesUrl === '') {
                    continue;
                }

                $forumsSet = is_array($row['forums_set'] ?? null) ? $row['forums_set'] : [];

                if (!empty($forumsSet)) {
                    if (isset($forumsSet[$fid])) {
                        $explicitMatches[] = $row;
                    }
                } else {
                    $globalMatches[] = $row;
                }
            }

            $sortGroups = static function (array &$items): void {
                usort($items, static function (array $a, array $b): int {
                    $sa = (int)($a['sortorder'] ?? 0);
                    $sb = (int)($b['sortorder'] ?? 0);

                    if ($sa === $sb) {
                        return ((int)($a['gid'] ?? 0)) <=> ((int)($b['gid'] ?? 0));
                    }

                    return $sa <=> $sb;
                });
            };

            if (!empty($explicitMatches)) {
                $sortGroups($explicitMatches);
                $group = $explicitMatches[0];
            } elseif (!empty($globalMatches)) {
                $sortGroups($globalMatches);
                $group = $globalMatches[0];
            }
        }
    }

    if (!$group || !is_array($group)) {
        return '';
    }

    $charUrl  = trim((string)($group['catalog_characters_url'] ?? ''));
    $rolesUrl = trim((string)($group['catalog_roles_url'] ?? ''));

    if ($charUrl === '' && $rolesUrl === '') {
        return '';
    }

    $charLabel  = trim((string)($group['catalog_characters_label'] ?? ''));
    $rolesLabel = trim((string)($group['catalog_roles_label'] ?? ''));

    if ($charLabel === '') {
        $charLabel = 'Персонажи';
    }

    if ($rolesLabel === '') {
        $rolesLabel = 'Роли';
    }

    $items = [];

    if ($charUrl !== '') {
        $items[] = '<a class="button af-atf-kb-catalog-cta-link" data-af-atf-kb-catalog-cta="1" data-af-atf-modal-title="'
            . htmlspecialchars_uni($charLabel)
            . '" href="' . htmlspecialchars_uni($charUrl) . '">' . htmlspecialchars_uni($charLabel) . '</a>';
    }

    if ($rolesUrl !== '') {
        $items[] = '<a class="button" href="' . htmlspecialchars_uni($rolesUrl) . '">' . htmlspecialchars_uni($rolesLabel) . '</a>';
    }

    if (empty($items)) {
        return '';
    }

    return '<span class="af-atf-catalog-cta" style="display:inline-flex;align-items:center;gap:8px;margin-right:8px;">'
        . implode('', $items)
        . '</span>';
}
/* -------------------- CACHE -------------------- */

function af_atf_get_fields_cached(): array
{
    global $cache;

    $c = $cache->read('af_atf_fields');
    if (!is_array($c) || empty($c['fields']) || !is_array($c['fields'])) {
        af_atf_rebuild_cache(true);
        $c = $cache->read('af_atf_fields');
    }

    return (is_array($c) && isset($c['fields']) && is_array($c['fields'])) ? $c['fields'] : [];
}

function af_atf_rebuild_cache(bool $force = false): void
{
    global $db, $cache;

    // Подтягиваем группы: именно там хранится forums/active
    $groups = [];
    if ($db->table_exists(AF_ATF_TABLE_GROUPS)) {
        $groupSelectFields = implode(',', af_atf_group_cache_select_fields());

        $qg = $db->simple_select(
            AF_ATF_TABLE_GROUPS,
            $groupSelectFields,
            '',
            ['order_by' => 'sortorder', 'order_dir' => 'ASC']
        );

        while ($g = $db->fetch_array($qg)) {
            $gid = (int)$g['gid'];

            $forumsRaw = trim((string)($g['forums'] ?? ''));
            $forumsSet = af_atf_forums_csv_to_set($forumsRaw);

            // expanded CSV (не обязательно, но удобно для дебага)
            $forumsExpanded = '';
            if (!empty($forumsSet)) {
                $forumsExpanded = implode(',', array_map('intval', array_keys($forumsSet)));
            }

            $groups[$gid] = [
                'gid'            => $gid,
                'title'          => (string)($g['title'] ?? ''),
                'forums_raw'     => $forumsRaw,
                'forums_set'     => $forumsSet,
                'forums_expanded'=> $forumsExpanded,
                'active'         => (int)($g['active'] ?? 0),
                'sortorder'      => (int)($g['sortorder'] ?? 0),
                'character_mechanic_mode' => (string)($g['character_mechanic_mode'] ?? 'auto'),

                // новые поля: если колонок ещё нет в БД — спокойно даём дефолты
                'catalog_characters_url'   => (string)($g['catalog_characters_url'] ?? ''),
                'catalog_roles_url'        => (string)($g['catalog_roles_url'] ?? ''),
                'catalog_characters_label' => (string)($g['catalog_characters_label'] ?? ''),
                'catalog_roles_label'      => (string)($g['catalog_roles_label'] ?? ''),
                'show_catalog_cta'         => (int)($g['show_catalog_cta'] ?? 0),
            ];
        }
    }

    $fields = [];
    if ($db->table_exists(AF_ATF_TABLE_FIELDS)) {
        $q = $db->simple_select(AF_ATF_TABLE_FIELDS, '*', '', ['order_by' => 'sortorder', 'order_dir' => 'ASC']);
        while ($row = $db->fetch_array($q)) {
            $row['fieldid'] = (int)$row['fieldid'];
            $row['groupid'] = (int)$row['groupid'];

            // IMPORTANT:
            // groupid=0 = "без группы" => считаем активным и без ограничения по форумам
            if ($row['groupid'] === 0) {
                $row['forums'] = '';
                $row['forums_set'] = [];
                $row['forums_expanded'] = '';
                $row['group_active'] = 1;
                $row['group_title']  = '';
            } else {
                $g = $groups[$row['groupid']] ?? null;

                // legacy: оставим исходный raw в forums (чтобы ACP/выводы не ломались)
                $row['forums'] = $g ? (string)$g['forums_raw'] : '';

                // новый быстрый доступ
                $row['forums_set'] = $g ? (array)$g['forums_set'] : [];
                $row['forums_expanded'] = $g ? (string)$g['forums_expanded'] : '';

                $row['group_active'] = $g ? (int)$g['active'] : 0;
                $row['group_title']  = $g ? (string)$g['title'] : '';
            }

            $row['required'] = (int)$row['required'];
            $row['active'] = (int)$row['active'];
            $row['show_thread'] = (int)$row['show_thread'];
            $row['show_forum'] = (int)$row['show_forum'];
            $row['sortorder'] = (int)$row['sortorder'];
            $row['maxlen'] = (int)$row['maxlen'];

            $row['allow_html'] = isset($row['allow_html']) ? (int)$row['allow_html'] : 0;
            $row['parse_mycode'] = isset($row['parse_mycode']) ? (int)$row['parse_mycode'] : 1;
            $row['parse_smilies'] = isset($row['parse_smilies']) ? (int)$row['parse_smilies'] : 1;

            $fields[] = $row;
        }
    }

    $cache->update('af_atf_fields', [
        'built'  => TIME_NOW,
        'fields' => $fields,
        'groups' => array_values($groups),
    ]);
}

function af_atf_is_first_post_context(array $post = [], int $pidCandidate = 0): bool
{
    global $thread, $tid, $db;

    $pid = $pidCandidate > 0 ? $pidCandidate : (int)($post['pid'] ?? 0);
    if ($pid <= 0) {
        return false;
    }

    // 1) Если MyBB дал firstpost прямо в $post (иногда это PID первого поста)
    if (isset($post['firstpost'])) {
        $fp = (int)$post['firstpost'];

        // редкий случай: булевый флаг
        if ($fp === 1) {
            return true;
        }

        if ($fp > 0 && $pid === $fp) {
            return true;
        }
    }

    // 2) Если есть $thread — обычно там firstpost всегда корректный
    if (isset($thread) && is_array($thread) && !empty($thread['firstpost'])) {
        if ((int)$thread['firstpost'] === $pid) {
            return true;
        }
    }

    // 3) Фолбэк: достанем firstpost из БД по tid
    $tid2 = (int)($post['tid'] ?? 0);
    if ($tid2 <= 0 && isset($thread) && is_array($thread) && !empty($thread['tid'])) {
        $tid2 = (int)$thread['tid'];
    }
    if ($tid2 <= 0 && !empty($tid)) {
        $tid2 = (int)$tid;
    }
    if ($tid2 <= 0 || !is_object($db)) {
        return false;
    }

    static $cache = [];
    if (!array_key_exists($tid2, $cache)) {
        $cache[$tid2] = 0;

        // threads.firstpost = PID первого поста
        $q = $db->simple_select('threads', 'firstpost', "tid=".(int)$tid2, ['limit' => 1]);
        $fp = (int)$db->fetch_field($q, 'firstpost');
        $cache[$tid2] = $fp > 0 ? $fp : 0;
    }

    return ($cache[$tid2] > 0 && $cache[$tid2] === $pid);
}

function af_atf_get_post_context_by_pid(int $pid): array
{
    global $db;

    $pid = (int)$pid;
    if ($pid <= 0 || !is_object($db)) {
        return ['pid' => 0, 'tid' => 0, 'fid' => 0, 'is_first' => false];
    }

    $q = $db->simple_select('posts', 'pid,tid,fid', "pid={$pid}", ['limit' => 1]);
    $row = $db->fetch_array($q);

    if (!is_array($row) || empty($row)) {
        return ['pid' => $pid, 'tid' => 0, 'fid' => 0, 'is_first' => false];
    }

    $tid = (int)($row['tid'] ?? 0);
    $fid = (int)($row['fid'] ?? 0);

    $isFirst = false;
    if ($tid > 0) {
        $q2 = $db->simple_select('threads', 'firstpost', "tid={$tid}", ['limit' => 1]);
        $first = (int)$db->fetch_field($q2, 'firstpost');
        $isFirst = ($first > 0 && $first === $pid);
    }

    return ['pid' => $pid, 'tid' => $tid, 'fid' => $fid, 'is_first' => $isFirst];
}


function af_atf_collect_posted_values(array $fields): array
{
    global $mybb;

    $incoming = $mybb->get_input('af_tf', MyBB::INPUT_ARRAY);
    if (!is_array($incoming)) {
        $incoming = [];
    }

    $values = [];
    foreach ($fields as $f) {
        $fieldid = (int)$f['fieldid'];
        if ($fieldid <= 0) {
            continue;
        }

        $raw = $incoming[$fieldid] ?? null;

        // checkbox: если чекбокс не отмечен — ключа может не быть вообще
        if ((string)$f['type'] === 'checkbox') {
            $values[$fieldid] = ($raw === '1' || $raw === 1) ? '1' : '';
            continue;
        }

        // обычные поля
        if (is_array($raw)) {
            $values[$fieldid] = '';
        } else {
            $values[$fieldid] = is_string($raw) ? $raw : (string)$raw;
        }
    }

    return $values;
}

function af_atf_is_preview_request(): bool
{
    global $mybb;

    // В MyBB preview-кнопка обычно name="previewpost" (значение — текст кнопки)
    if (isset($mybb) && is_object($mybb)) {
        $p = $mybb->get_input('previewpost');
        if (is_string($p) && $p !== '') {
            return true;
        }

        // на всякий случай (некоторые темы/кастомы)
        $p2 = $mybb->get_input('preview');
        if (is_string($p2) && $p2 !== '') {
            return true;
        }
    }

    return (!empty($_POST['previewpost']) || !empty($_POST['preview']));
}

function af_atf_build_preview_block_from_post(int $fid): string
{
    global $templates;

    $fid = (int)$fid;
    if ($fid <= 0) {
        return '';
    }

    $fields = af_atf_get_fields_for_forum($fid);
    if (empty($fields)) {
        return '';
    }

    $values = af_atf_collect_posted_values($fields);
    $GLOBALS['af_atf_context_fid'] = $fid;
    $GLOBALS['af_atf_context_fields'] = $fields;
    $GLOBALS['af_atf_context_values'] = $values;

    $rows = '';
    foreach ($fields as $f) {
        if ((int)($f['show_thread'] ?? 0) !== 1) {
            continue;
        }

        $fieldid = (int)$f['fieldid'];
        if ($fieldid <= 0) {
            continue;
        }

        $val = isset($values[$fieldid]) ? trim((string)$values[$fieldid]) : '';
        if ($val === '') {
            continue;
        }

        $nameClass = preg_replace('~[^a-z0-9_]+~i', '_', (string)$f['name']);
        $label = htmlspecialchars_uni($f['title']);

        $valueHtml = af_atf_format_value_for_display($f, $val);

        $format = trim((string)$f['format']);
        if ($format === '') {
            $format = '<span class="af-atf-label">{LABEL}:</span> <span class="af-atf-value">{VALUE}</span>';
        }

        $line = str_replace(['{LABEL}', '{VALUE}'], [$label, $valueHtml], $format);
        $line = '<div class="af-atf-field af-atf-field-'.$nameClass.'" data-fieldid="'.$fieldid.'">'.$line.'</div>';

        $row = '';
        eval("\$row = \"".$templates->get('af_atf_display_row')."\";");
        $rows .= $row;
    }

    if ($rows === '') {
        return '';
    }

    $block = '';
    eval("\$block = \"".$templates->get('af_atf_display_block')."\";");

    // Небольшая шапка, чтобы было понятно, что это превью полей
    return "\n<div class=\"af-atf-preview\">\n"
         . "<div class=\"af-atf-preview-title\"><strong>Поля темы (превью)</strong></div>\n"
         . $block
         . "\n</div>\n";
}

function af_atf_prepare_input_block(int $fid, int $tid = 0, bool $isEdit = false): void
{
    global $mybb, $af_atf_input_html;

    $fid = (int)$fid;
    $tid = (int)$tid;

    $fields = af_atf_get_fields_for_forum($fid);

    // флаг: если есть поля — скрываем редактор
    $GLOBALS['af_atf_hide_editor'] = !empty($fields);

    if (empty($fields)) {
        $af_atf_input_html = '';
        return;
    }

    $GLOBALS['af_atf_context_fid'] = $fid;

    // 1) при POST/preview/ошибках — ВСЕГДА берём значения из POST, чтобы не стирались
    $isPost = false;
    if (isset($mybb->request_method)) {
        $isPost = ($mybb->request_method === 'post');
    } else {
        $isPost = (strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST');
    }

    $values = [];
    if ($isPost) {
        $values = af_atf_collect_posted_values($fields);
    }

    if (!$isPost && !$isEdit && empty($values)) {
        $prefillByName = (array)($GLOBALS['af_atf_prefill_named_values'] ?? []);
        if (!empty($prefillByName)) {
            $values = af_atf_map_named_values_to_fieldids($fields, $prefillByName);
        }
    }

    // 2) если это edit и POST пустой (первый заход на страницу редактирования) — берём из БД
    if ($isEdit && empty($values) && $tid > 0) {
        $values = af_atf_get_values_by_tid($tid);
    }

    $GLOBALS['af_atf_context_fields'] = $fields;
    $GLOBALS['af_atf_context_values'] = $values;
    $af_atf_input_html = af_atf_render_inputs($fields, $values);
}

function af_atf_map_named_values_to_fieldids(array $fields, array $namedValues): array
{
    $out = [];
    if (empty($fields) || empty($namedValues)) {
        return $out;
    }

    $statsByKey = [];
    $statsJson = '';
    if (array_key_exists('character_stats', $namedValues)) {
        $rawStats = $namedValues['character_stats'];
        if (is_string($rawStats) && trim($rawStats) !== '') {
            $decoded = json_decode($rawStats, true);
            if (is_array($decoded)) {
                foreach ($decoded as $k => $v) {
                    if (!is_scalar($v)) {
                        continue;
                    }
                    $statsByKey[(string)$k] = trim((string)$v);
                }
            }
            $statsJson = af_atf_normalize_character_stats_json($rawStats);
        } elseif (is_array($rawStats)) {
            foreach ($rawStats as $k => $v) {
                if (!is_scalar($v)) {
                    continue;
                }
                $statsByKey[(string)$k] = trim((string)$v);
            }
            $statsJson = af_atf_normalize_character_stats_json(json_encode($rawStats, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}');
        }
    }

    foreach ($fields as $field) {
        $fieldName = trim((string)($field['name'] ?? ''));
        $fieldId = (int)($field['fieldid'] ?? 0);
        $fieldType = trim((string)($field['type'] ?? ''));
        if ($fieldName === '' || $fieldId <= 0) {
            continue;
        }

        if ($fieldType === 'character_stats') {
            if ($statsJson !== '' && $statsJson !== '{}') {
                $out[$fieldId] = $statsJson;
                continue;
            }

            $statsPayload = [];
            foreach (af_atf_character_stats_field_keys() as $statKey) {
                if (array_key_exists($statKey, $namedValues) && is_scalar($namedValues[$statKey])) {
                    $statsPayload[$statKey] = trim((string)$namedValues[$statKey]);
                } elseif (array_key_exists($statKey, $statsByKey)) {
                    $statsPayload[$statKey] = $statsByKey[$statKey];
                }
            }

            if (!empty($statsPayload)) {
                $out[$fieldId] = af_atf_normalize_character_stats_json(json_encode($statsPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}');
            }
            continue;
        }

        if ($fieldType === 'character_abilities') {
            $rawAbilities = null;
            if (array_key_exists($fieldName, $namedValues)) {
                $rawAbilities = $namedValues[$fieldName];
            } elseif (array_key_exists('character_abilities', $namedValues)) {
                $rawAbilities = $namedValues['character_abilities'];
            }
            if ($rawAbilities === null) {
                continue;
            }
            if (is_array($rawAbilities) || is_object($rawAbilities)) {
                $rawAbilities = json_encode($rawAbilities, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            }
            $out[$fieldId] = af_atf_normalize_character_abilities_json(is_string($rawAbilities) ? $rawAbilities : (string)$rawAbilities);
            continue;
        }

        $value = null;
        if (array_key_exists($fieldName, $namedValues)) {
            $value = $namedValues[$fieldName];
        } elseif (strpos($fieldName, 'character_') === 0 && array_key_exists($fieldName, $statsByKey)) {
            $value = $statsByKey[$fieldName];
        }
        if ($value === null) {
            continue;
        }

        if (is_array($value) || is_object($value)) {
            $value = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
        $out[$fieldId] = is_string($value) ? $value : (string)$value;
    }

    return $out;
}

function af_atf_boot_prefill_from_token(int $fid): void
{
    global $mybb, $cache;

    $GLOBALS['af_atf_prefill_named_values'] = [];
    $GLOBALS['af_atf_prefill_mechanic'] = '';
    $GLOBALS['af_atf_prefill_kb_entry'] = [];

    $token = trim((string)$mybb->get_input('af_atf_prefill_token'));
    if ($token === '' || !preg_match('~^[a-f0-9]{16,128}$~i', $token)) {
        return;
    }
    if (!is_object($cache) || !method_exists($cache, 'read')) {
        return;
    }

    $uid = (int)($mybb->user['uid'] ?? 0);
    $stored = [];
    if (function_exists('af_atf_prefill_store_consume')) {
        $stored = af_atf_prefill_store_consume($token, $uid, $fid);
    }
    if (!empty($stored)) {
        $values = (array)($stored['values'] ?? []);
        if (!empty($values)) {
            $GLOBALS['af_atf_prefill_named_values'] = $values;
        }
        $entry = (array)($stored['entry'] ?? []);
        if (!empty($entry['id']) && !empty($entry['type']) && !empty($entry['key'])) {
            $GLOBALS['af_atf_prefill_kb_entry'] = [
                'id' => (int)$entry['id'],
                'type' => (string)$entry['type'],
                'key' => (string)$entry['key'],
            ];
        }
        $mechanic = trim((string)($stored['mechanic'] ?? ''));
        if ($mechanic === 'arpg' || $mechanic === 'dnd') {
            $GLOBALS['af_atf_prefill_mechanic'] = $mechanic;
        }
        return;
    }

    $cacheKey = 'af_atf_prefill_' . strtolower($token);
    $payload = $cache->read($cacheKey);
    if (!is_array($payload)) {
        return;
    }

    if ($uid <= 0 || (int)($payload['uid'] ?? 0) !== $uid) {
        return;
    }
    if ((int)($payload['expires_at'] ?? 0) < TIME_NOW) {
        return;
    }
    if ((int)($payload['forum_id'] ?? 0) !== $fid) {
        return;
    }

    $values = (array)($payload['values'] ?? []);
    if (!empty($values)) {
        $GLOBALS['af_atf_prefill_named_values'] = $values;
    }
    $entry = (array)($payload['entry'] ?? []);
    if (!empty($entry['id']) && !empty($entry['type']) && !empty($entry['key'])) {
        $GLOBALS['af_atf_prefill_kb_entry'] = [
            'id' => (int)$entry['id'],
            'type' => (string)$entry['type'],
            'key' => (string)$entry['key'],
        ];
    }
    $mechanic = trim((string)($payload['mechanic'] ?? ''));
    if ($mechanic === 'arpg' || $mechanic === 'dnd') {
        $GLOBALS['af_atf_prefill_mechanic'] = $mechanic;
    }

    if (method_exists($cache, 'delete')) {
        $cache->delete($cacheKey);
    } else {
        $cache->update($cacheKey, null);
    }
}

/* -------------------- INPUT RENDER (newthread/editpost) -------------------- */
function af_atf_newthread_start(): void
{
    global $fid, $mybb;

    $fidI = (int)($fid ?? 0);
    if ($fidI <= 0 && isset($mybb) && is_object($mybb)) {
        $fidI = (int)$mybb->get_input('fid', MyBB::INPUT_INT);
    }

    if ($fidI > 0) {
        $fid = $fidI; // синхронизируем глобалку
        af_atf_boot_prefill_from_token($fidI);
    }

    // чтобы при POST/preview/ошибках поля не стирались
    af_atf_prepare_input_block($fidI, 0, false);

    // Сбрасываем на всякий случай (чтоб не "прилипало" между запросами)
    $GLOBALS['af_atf_preview_html'] = '';

    // Preview-кнопка: MyBB потом перезапишет $preview, поэтому
    // мы ТОЛЬКО готовим HTML здесь, а вставим его в pre_output_page.
    $isPost = (isset($mybb->request_method) ? ($mybb->request_method === 'post') : (strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST'));
    if ($isPost && af_atf_is_preview_request()) {
        af_atf_force_min_message_if_needed($fidI);

        $extra = af_atf_build_preview_block_from_post($fidI);
        if ($extra !== '') {
            $GLOBALS['af_atf_preview_html'] = $extra;
        }
    }
}

function af_atf_editpost_start(): void
{
    global $fid, $tid, $pid, $post, $thread, $mybb, $af_atf_input_html;

    $pidI = (int)($pid ?? 0);
    if ($pidI <= 0 && isset($mybb) && is_object($mybb)) {
        $pidI = (int)$mybb->get_input('pid', MyBB::INPUT_INT);
    }

    // если pid не получили — ничего не делаем
    if ($pidI <= 0) {
        $af_atf_input_html = '';
        $GLOBALS['af_atf_hide_editor'] = false;
        $GLOBALS['af_atf_preview_html'] = '';
        return;
    }

    // надёжно дёргаем fid/tid + проверяем "первый ли пост"
    $ctx = af_atf_get_post_context_by_pid($pidI);

    if (empty($ctx['is_first'])) {
        $af_atf_input_html = '';
        $GLOBALS['af_atf_hide_editor'] = false;
        $GLOBALS['af_atf_preview_html'] = '';
        return;
    }

    $fidI = (int)($ctx['fid'] ?? 0);
    $tidI = (int)($ctx['tid'] ?? 0);

    if ($fidI > 0) { $fid = $fidI; }
    if ($tidI > 0) { $tid = $tidI; }

    af_atf_prepare_input_block($fidI, $tidI, true);

    // Сбрасываем на всякий случай
    $GLOBALS['af_atf_preview_html'] = '';

    // Preview может быть построен позже ядром MyBB и перезаписать $preview,
    // поэтому готовим HTML тут, а вставляем в pre_output_page.
    $isPost = (isset($mybb->request_method) ? ($mybb->request_method === 'post') : (strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST'));
    if ($isPost && af_atf_is_preview_request()) {
        af_atf_force_min_message_if_needed($fidI);

        $extra = af_atf_build_preview_block_from_post($fidI);
        if ($extra !== '') {
            $GLOBALS['af_atf_preview_html'] = $extra;
        }
    }
}

function af_atf_render_field_description(array $field): string
{
    $raw = isset($field['description']) ? (string)$field['description'] : '';
    $raw = trim($raw);

    if ($raw === '') {
        return '';
    }

    // Нормализуем переносы
    $raw = str_replace(["\r\n", "\r"], "\n", $raw);

    /**
     * Доп. удобство:
     * если админ случайно вставил HTML (как в твоём примере <a><b><u>),
     * пробуем конвертнуть самые частые теги в MyCode, чтобы оно тоже “ожило”
     * даже при выключенном allowhtml на форуме.
     */
    if (strpos($raw, '<') !== false && strpos($raw, '>') !== false) {
        // <br> -> перенос
        $raw = preg_replace('~<\s*br\s*/?\s*>~i', "\n", $raw);

        // b/strong
        $raw = preg_replace('~<\s*(b|strong)\s*>~i', '[b]', $raw);
        $raw = preg_replace('~<\s*/\s*(b|strong)\s*>~i', '[/b]', $raw);

        // u
        $raw = preg_replace('~<\s*u\s*>~i', '[u]', $raw);
        $raw = preg_replace('~<\s*/\s*u\s*>~i', '[/u]', $raw);

        // i/em
        $raw = preg_replace('~<\s*(i|em)\s*>~i', '[i]', $raw);
        $raw = preg_replace('~<\s*/\s*(i|em)\s*>~i', '[/i]', $raw);

        // <a href="..."> -> [url=...]
        $raw = preg_replace_callback('~<\s*a\b([^>]*)>~i', static function ($m) {
            $attrs = (string)($m[1] ?? '');
            $href = '';

            if (preg_match('~\bhref\s*=\s*(["\'])(.*?)\1~i', $attrs, $hm)) {
                $href = (string)$hm[2];
            } elseif (preg_match('~\bhref\s*=\s*([^\s>]+)~i', $attrs, $hm)) {
                $href = (string)$hm[1];
            }

            $href = trim(html_entity_decode($href, ENT_QUOTES, 'UTF-8'));
            if ($href === '') {
                return '';
            }

            // микро-защита от мусора
            if (preg_match('~^\s*javascript:~i', $href)) {
                return '';
            }

            return '[url=' . $href . ']';
        }, $raw);

        $raw = preg_replace('~<\s*/\s*a\s*>~i', '[/url]', $raw);

        // Остальной HTML выкидываем, чтобы не тащить чужие теги
        $raw = strip_tags($raw);
    }

    // Парсим MyCode (ссылки/жирный/подчёркивание и т.д.)
    return af_atf_parse_message($raw, [
        'allow_html'    => 0,
        'allow_mycode'  => 1,
        'allow_smilies' => 0,
    ]);
}

function af_atf_kb_get_list_by_type_any(string $type): array
{
    $type = strtolower(trim($type));
    if ($type === '' || !preg_match('/^[a-z0-9_]{2,64}$/', $type)) {
        return [];
    }

    if (in_array($type, af_atf_kb_allowed_types(), true)) {
        return af_atf_kb_get_list_by_type($type);
    }

    global $db;
    if (!is_object($db) || !$db->table_exists('af_kb_entries')) {
        return [];
    }

    $items = [];
    $q = $db->simple_select(
        'af_kb_entries',
        'type,`key`,title_ru,title_en,short_ru,short_en,icon_url,icon_class,sortorder',
        "type='" . $db->escape_string($type) . "' AND active=1",
        ['order_by' => 'sortorder, title_ru, title_en', 'order_dir' => 'ASC']
    );
    while ($row = $db->fetch_array($q)) {
        $items[] = [
            'key' => (string)($row['key'] ?? ''),
            'title' => af_atf_kb_pick_text($row, 'title') ?: (string)($row['key'] ?? ''),
            'short' => af_atf_kb_pick_text($row, 'short'),
            'icon_url' => (string)($row['icon_url'] ?? ''),
            'icon_class' => (string)($row['icon_class'] ?? ''),
        ];
    }

    return $items;
}

function af_atf_character_infer_mechanic(array $fields, array $valuesByFieldId): string
{
    $fieldNameById = [];
    foreach ($fields as $f) {
        $fieldNameById[(int)($f['fieldid'] ?? 0)] = (string)($f['name'] ?? '');
    }

    $valueByName = [];
    foreach ($valuesByFieldId as $fieldid => $val) {
        $name = (string)($fieldNameById[(int)$fieldid] ?? '');
        if ($name !== '') {
            $valueByName[$name] = trim((string)$val);
        }
    }

    if (($valueByName['character_element'] ?? '') !== '' || ($valueByName['character_faction'] ?? '') !== '') {
        return 'arpg';
    }

    $race = (string)($valueByName['character_race'] ?? '');
    $class = (string)($valueByName['character_class'] ?? '');
    if ($race !== '' && !empty(af_atf_kb_get_entry('arpg_origin', $race))) {
        return 'arpg';
    }
    if ($class !== '' && !empty(af_atf_kb_get_entry('arpg_archetype', $class))) {
        return 'arpg';
    }

    if (function_exists('af_kb_get_default_mechanic_mode')) {
        $mode = (string)af_kb_get_default_mechanic_mode();
        if ($mode === 'arpg' || $mode === 'dnd') {
            return $mode;
        }
    }

    // fallback for empty direct newthread:
    // if contract fields are explicitly scoped to ARPG, use ARPG as initial mode.
    foreach ($fields as $f) {
        $fieldName = trim((string)($f['name'] ?? ''));
        if (strpos($fieldName, 'character_') !== 0) {
            continue;
        }
        $opts = af_atf_parse_options((string)($f['options'] ?? ''));
        $mechanic = trim((string)($opts['mechanic'] ?? ''));
        if ($mechanic === 'arpg') {
            return 'arpg';
        }
        $provider = trim((string)($opts['provider'] ?? ''));
        if ($provider !== '' && af_atf_character_provider_type($provider, 'arpg') !== '' && af_atf_character_provider_type($provider, 'dnd') === '') {
            return 'arpg';
        }
    }

    return 'dnd';
}

function af_atf_character_provider_type(string $provider, string $mechanic): string
{
    $provider = trim($provider);
    $mechanic = $mechanic === 'arpg' ? 'arpg' : 'dnd';

    $map = [
        'character_race' => ['dnd' => 'kb_race', 'arpg' => 'arpg_origin'],
        'character_origin' => ['dnd' => 'kb_race', 'arpg' => 'arpg_origin'],
        'character_class' => ['dnd' => 'kb_class', 'arpg' => 'arpg_archetype'],
        'character_archetype' => ['dnd' => 'kb_class', 'arpg' => 'arpg_archetype'],
        'character_faction' => ['dnd' => '', 'arpg' => 'arpg_faction'],
        'character_element' => ['dnd' => '', 'arpg' => 'arpg_element'],
    ];

    if (!isset($map[$provider])) {
        return '';
    }

    return (string)($map[$provider][$mechanic] ?? '');
}

function af_atf_character_arpg_contract_types(): array
{
    return [
        'character_element' => 'arpg_element',
        'character_race' => 'arpg_origin',
        'character_origin' => 'arpg_origin',
        'character_class' => 'arpg_archetype',
        'character_archetype' => 'arpg_archetype',
        'character_faction' => 'arpg_faction',
    ];
}

function af_atf_character_arpg_contract_type(string $fieldName): string
{
    $fieldName = trim($fieldName);
    if ($fieldName === '') {
        return '';
    }

    $map = af_atf_character_arpg_contract_types();
    return (string)($map[$fieldName] ?? '');
}

function af_atf_get_character_ability_select_payload(): array
{
    $setMap = [
        'type' => 'ability_type',
        'subtype' => 'ability_subtype',
        'slot' => 'ability_slot',
        'damage_type' => 'ability_damage_type',
        'targeting' => 'ability_targeting',
    ];

    $payload = [];
    foreach ($setMap as $target => $setKey) {
        $rows = [];
        if (function_exists('af_kb_get_arpg_mechanics_options')) {
            $rows = (array)af_kb_get_arpg_mechanics_options($setKey);
        }
        if (empty($rows) && function_exists('af_kb_arpg_mechanics_options_fallback')) {
            $rows = (array)af_kb_arpg_mechanics_options_fallback($setKey);
        }
        $normalized = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $key = trim((string)($row['key'] ?? ''));
            if ($key === '') {
                continue;
            }
            $labelRu = trim((string)($row['label_ru'] ?? ''));
            $labelEn = trim((string)($row['label_en'] ?? ''));
            $normalized[] = [
                'key' => $key,
                'label_ru' => $labelRu !== '' ? $labelRu : ($labelEn !== '' ? $labelEn : $key),
                'label_en' => $labelEn !== '' ? $labelEn : ($labelRu !== '' ? $labelRu : $key),
            ];
        }
        $payload[$target] = $normalized;
    }

    return $payload;
}

function af_atf_get_arpg_mechanics_select_options(string $setKey): array
{
    $setKey = trim($setKey);
    if ($setKey === '') {
        return [];
    }

    $rows = [];
    if (function_exists('af_kb_get_arpg_mechanics_options')) {
        $rows = (array)af_kb_get_arpg_mechanics_options($setKey);
    }

    $normalized = [];
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $key = trim((string)($row['key'] ?? ''));
        if ($key === '') {
            continue;
        }
        $labelRu = trim((string)($row['label_ru'] ?? ''));
        $labelEn = trim((string)($row['label_en'] ?? ''));
        $normalized[] = [
            'key' => $key,
            'label_ru' => $labelRu !== '' ? $labelRu : ($labelEn !== '' ? $labelEn : $key),
            'label_en' => $labelEn !== '' ? $labelEn : ($labelRu !== '' ? $labelRu : $key),
        ];
    }

    return $normalized;
}

function af_atf_character_effective_mechanic_for_render(string $fallback = ''): string
{
    $mechanic = ($fallback === 'arpg' || $fallback === 'dnd') ? $fallback : af_atf_character_active_mechanic();
    if ($mechanic === 'arpg') {
        return 'arpg';
    }

    $ctxFields = $GLOBALS['af_atf_context_fields'] ?? [];
    if (!is_array($ctxFields)) {
        return $mechanic === 'dnd' ? 'dnd' : 'dnd';
    }

    foreach ($ctxFields as $f) {
        $fieldName = (string)($f['name'] ?? '');
        if ($fieldName === 'character_element' || $fieldName === 'character_faction') {
            return 'arpg';
        }
    }

    return $mechanic === 'dnd' ? 'dnd' : 'dnd';
}

function af_atf_character_resolve_kb_type(array $field, string $value = '', string $forcedMechanic = ''): string
{
    $opts = af_atf_parse_options((string)($field['options'] ?? ''));
    $provider = trim((string)($opts['provider'] ?? ''));
    $kbType = trim((string)($opts['kb_type'] ?? ''));
    $type = trim((string)($field['type'] ?? ''));

    $mechanic = af_atf_character_effective_mechanic_for_render($forcedMechanic);
    if ($type === 'kb_dynamic') {
        $scope = trim((string)($opts['mechanic'] ?? ''));
        if ($scope === 'arpg' || $scope === 'dnd') {
            $mechanic = $scope;
        }
    }

    $mapped = [];
    if ($provider !== '') {
        foreach (['arpg', 'dnd'] as $mode) {
            $mapped[$mode] = af_atf_character_provider_type($provider, $mode);
        }
        $providerType = (string)($mapped[$mechanic] ?? '');
        if ($providerType !== '') {
            $kbType = $providerType;
        }
    }

    $value = trim($value);
    if ($value !== '') {
        if ($kbType !== '' && !empty(af_atf_kb_get_entry($kbType, $value))) {
            return $kbType;
        }
        foreach (['arpg', 'dnd'] as $mode) {
            $candidate = (string)($mapped[$mode] ?? '');
            if ($candidate === '') {
                continue;
            }
            if (!empty(af_atf_kb_get_entry($candidate, $value))) {
                return $candidate;
            }
        }
    }

    return $kbType;
}

function af_atf_character_group_mechanic_for_forum(int $fid): string
{
    if ($fid <= 0) {
        return '';
    }

    $groups = af_atf_get_groups_cached();
    if (empty($groups)) {
        return '';
    }

    foreach ($groups as $group) {
        if ((int)($group['active'] ?? 0) !== 1) {
            continue;
        }

        $forumsSet = is_array($group['forums_set'] ?? null) ? $group['forums_set'] : [];
        if (!empty($forumsSet) && !isset($forumsSet[$fid])) {
            continue;
        }

        $mode = trim((string)($group['character_mechanic_mode'] ?? ''));
        if ($mode === 'arpg' || $mode === 'dnd') {
            return $mode;
        }
    }

    return '';
}

function af_atf_character_resolve_active_mechanic(int $fid, array $fields, array $valuesByFieldId): string
{
    $forced = af_atf_character_group_mechanic_for_forum($fid);
    if ($forced === 'arpg' || $forced === 'dnd') {
        return $forced;
    }

    $prefillMechanic = trim((string)($GLOBALS['af_atf_prefill_mechanic'] ?? ''));
    if ($prefillMechanic === 'arpg' || $prefillMechanic === 'dnd') {
        return $prefillMechanic;
    }

    return af_atf_character_infer_mechanic($fields, $valuesByFieldId);
}

function af_atf_render_inputs(array $fields, array $valuesByFieldId): string
{
    global $templates;

    if (empty($fields)) {
        return '';
    }

    $rows = '';
    $hasCharacterContract = false;
    foreach ($fields as $f) {
        if (strpos((string)($f['name'] ?? ''), 'character_') === 0) {
            $hasCharacterContract = true;
            break;
        }
    }

    if ($hasCharacterContract) {
        $fid = (int)($GLOBALS['af_atf_context_fid'] ?? 0);
        $activeMechanic = af_atf_character_resolve_active_mechanic($fid, $fields, $valuesByFieldId);
        $rows .= '<tr class="af-atf-character-mechanic-row" style="display:none;"><td colspan="2"><input type="hidden" class="af-atf-character-mechanic" value="' . htmlspecialchars_uni($activeMechanic) . '" /></td></tr>';
    }

    $characterTopFieldNames = [
        'character_element',
        'character_pic',
        'character_image',
        'character_prototype',
        'character_name',
        'character_name_ru',
        'character_nicknames',
        'character_age',
        'character_gen',
        'character_gender',
        'character_race',
        'character_origin',
        'character_class',
        'character_archetype',
        'character_faction',
        'character_activity',
        'character_occupation',
    ];
    $characterTopFieldNameMap = array_fill_keys($characterTopFieldNames, true);
    $characterTopRows = [];
    $skipFieldIds = [];

    foreach ($fields as $f) {
        $fieldName = trim((string)($f['name'] ?? ''));
        if ($fieldName === '' || !isset($characterTopFieldNameMap[$fieldName])) {
            continue;
        }

        $fieldid = (int)($f['fieldid'] ?? 0);
        if ($fieldid <= 0) {
            continue;
        }

        $valRaw = $valuesByFieldId[$fieldid] ?? '';
        $characterTopRows[] = [
            'field' => $f,
            'value' => is_string($valRaw) ? $valRaw : '',
        ];
    }

    if (count($characterTopRows) >= 10) {
        $gridHtml = '<div class="af-atf-character-top-grid">';
        foreach ($characterTopRows as $item) {
            $f = (array)$item['field'];
            $fieldid = (int)($f['fieldid'] ?? 0);
            if ($fieldid <= 0) {
                continue;
            }
            $skipFieldIds[$fieldid] = true;

            $title = htmlspecialchars_uni((string)($f['title'] ?? ''));
            $desc = af_atf_render_field_description($f);
            $required = ((int)($f['required'] ?? 0) === 1);
            $requiredMark = $required ? '<span class="af-atf-required">*</span>' : '';
            $input = af_atf_build_input_html($f, (string)$item['value']);

            $gridHtml .= '<div class="af-atf-character-top-grid-item">'
                . '<label class="af-atf-character-top-grid-label">' . $title . $requiredMark . '</label>'
                . ($desc !== '' ? '<div class="af-atf-character-top-grid-desc">' . $desc . '</div>' : '')
                . '<div class="af-atf-character-top-grid-control">' . $input . '</div>'
                . '</div>';
        }
        $gridHtml .= '</div>';
        $rows .= '<tr class="af-atf-character-top-grid-row"><td class="trow2" colspan="2">' . $gridHtml . '</td></tr>';
    }

    foreach ($fields as $f) {
        $fieldid = (int)$f['fieldid'];
        if (isset($skipFieldIds[$fieldid])) {
            continue;
        }

        $title   = htmlspecialchars_uni((string)$f['title']);
        $desc    = af_atf_render_field_description($f);

        $required = ((int)$f['required'] === 1);

        $valRaw = $valuesByFieldId[$fieldid] ?? '';
        $val = is_string($valRaw) ? $valRaw : '';

        $input = af_atf_build_input_html($f, $val);

        $requiredMark = $required ? '<span class="af-atf-required">*</span>' : '';

        $row = '';
        eval("\$row = \"".$templates->get('af_atf_input_row')."\";");
        $rows .= $row;
    }

    $out = '';
    eval("\$out = \"".$templates->get('af_atf_input_block')."\";");
    return $out;
}

function af_atf_build_input_html(array $field, string $value): string
{
    global $mybb;

    $type = (string)$field['type'];
    $fieldid = (int)$field['fieldid'];

    $nameAttr = 'af_tf['.$fieldid.']';

    $maxlen = (int)$field['maxlen'];
    $maxAttr = $maxlen > 0 ? ' maxlength="'.$maxlen.'"' : '';

    $opts = af_atf_parse_options((string)$field['options']);
    $safeValue = htmlspecialchars_uni($value);
    $fieldName = (string)($field['name'] ?? '');

    // textarea id нужен для BBCode-инсертора
    $taId = 'af_atf_ta_'.$fieldid;

    // helper: max selected users for usernames
    $maxUsers = 0;
    if ($type === 'usernames') {
        foreach (['max', 'limit', 'count'] as $k) {
            if (isset($opts[$k])) {
                $maxUsers = (int)$opts[$k];
                if ($maxUsers < 0) $maxUsers = 0;
                break;
            }
        }
    }

    $activeMechanic = af_atf_character_effective_mechanic_for_render();
    $arpgContractType = af_atf_character_arpg_contract_type($fieldName);

    if ($fieldName === 'character_gen' || $fieldName === 'character_gender') {
        $mechanicsRows = af_atf_get_arpg_mechanics_select_options('character_gender');
        if (!empty($mechanicsRows)) {
            $html = '<select class="select af-atf-input" name="' . $nameAttr . '"><option value=""></option>';
            $seen = [];
            foreach ($mechanicsRows as $row) {
                $key = trim((string)($row['key'] ?? ''));
                if ($key === '') {
                    continue;
                }
                $seen[$key] = true;
                $selected = ((string)$value === $key) ? ' selected="selected"' : '';
                $label = trim((string)($row['label_ru'] ?? ''));
                if ($label === '') {
                    $label = trim((string)($row['label_en'] ?? ''));
                }
                if ($label === '') {
                    $label = $key;
                }
                $html .= '<option value="' . htmlspecialchars_uni($key) . '"' . $selected . '>' . htmlspecialchars_uni($label) . '</option>';
            }
            if ($value !== '' && !isset($seen[$value])) {
                $html .= '<option value="' . htmlspecialchars_uni($value) . '" selected="selected">' . htmlspecialchars_uni($value) . '</option>';
            }
            $html .= '</select>';
            return $html;
        }
    }

    $renderKbSelect = static function (string $nameAttr, string $selectedValue, array $list, string $fallbackLabel = ''): string {
        $html = '<select class="select af-atf-input" name="' . $nameAttr . '"><option value=""></option>';
        $seen = [];
        foreach ($list as $item) {
            $key = (string)($item['key'] ?? '');
            if ($key === '') {
                continue;
            }
            $seen[$key] = true;
            $selected = ($selectedValue === $key) ? ' selected="selected"' : '';
            $label = (string)($item['title'] ?? $key);
            $iconUrl = trim((string)($item['icon_url'] ?? ''));
            $iconClass = trim((string)($item['icon_class'] ?? ''));
            $tooltip = trim((string)($item['short'] ?? ''));
            $html .= '<option value="' . htmlspecialchars_uni($key) . '"'
                . $selected
                . ($iconUrl !== '' ? ' data-icon-url="' . htmlspecialchars_uni($iconUrl) . '"' : '')
                . ($iconClass !== '' ? ' data-icon-class="' . htmlspecialchars_uni($iconClass) . '"' : '')
                . ($tooltip !== '' ? ' data-tooltip="' . htmlspecialchars_uni($tooltip) . '"' : '')
                . '>' . htmlspecialchars_uni($label) . '</option>';
        }
        if ($selectedValue !== '' && !isset($seen[$selectedValue])) {
            $label = $fallbackLabel !== '' ? $fallbackLabel : $selectedValue;
            $html .= '<option value="' . htmlspecialchars_uni($selectedValue) . '" selected="selected">' . htmlspecialchars_uni($label) . '</option>';
        }
        $html .= '</select>';
        return $html;
    };

    $shouldForceArpgContract = $arpgContractType !== '' && (
        $activeMechanic === 'arpg'
        || $fieldName === 'character_element'
        || $fieldName === 'character_faction'
    );

    if ($shouldForceArpgContract) {
        $list = af_atf_kb_get_list_by_type_any($arpgContractType);
        $fallbackLabel = af_atf_kb_resolve_dynamic_label($field, $value);
        $html = '<div class="af-atf-kb-dynamic" data-character-field="' . htmlspecialchars_uni($fieldName) . '" data-mechanic-scope="arpg">';
        $html .= $renderKbSelect($nameAttr, $value, $list, $fallbackLabel);
        if ($fieldName === 'character_element') {
            $html .= '<div class="af-atf-kb-dynamic-preview" data-preview-role="element"></div>';
        }
        $html .= '</div>';
        return $html;
    }

    switch ($type) {
        case 'kb_race':
        case 'kb_class':
        case 'kb_theme': {
            $kbTypeMap = ['kb_race' => 'race', 'kb_class' => 'class', 'kb_theme' => 'theme'];
            $kbType = $kbTypeMap[$type] ?? '';
            if ($kbType === '') {
                return '';
            }
            $list = af_atf_kb_get_list_by_type($kbType);

            $html = '<div class="af-atf-kb-select" data-kb-type="'.htmlspecialchars_uni($kbType).'">';
            $html .= '<select class="select af-atf-input af-atf-kb-select-input" name="'.$nameAttr.'">';
            $html .= '<option value=""></option>';

            $seen = [];
            foreach ($list as $item) {
                $key = (string)($item['key'] ?? '');
                if ($key === '') {
                    continue;
                }
                $seen[$key] = true;
                $sel = ((string)$value === $key) ? ' selected="selected"' : '';
                $label = (string)($item['title'] ?? $key);
                $html .= '<option value="'.htmlspecialchars_uni($key).'"'.$sel.'>'.htmlspecialchars_uni($label).'</option>';
            }

            if ($value !== '' && !isset($seen[$value])) {
                $fallbackLabel = af_atf_kb_resolve_label((string)$field['options'], $value);
                $html .= '<option value="'.htmlspecialchars_uni($value).'" selected="selected">'.htmlspecialchars_uni($fallbackLabel).'</option>';
            }

            $html .= '</select>';

            $preview = af_atf_kb_build_chip($kbType, $value, (string)$field['options']);
            $html .= '<div class="af-atf-kb-preview">'.($preview !== '' ? $preview : '').'</div>';
            $html .= '</div>';

            return $html;
        }

        case 'kb_dynamic': {
            $mechanicScope = trim((string)($opts['mechanic'] ?? ''));
            $kbType = af_atf_character_resolve_kb_type($field, $value);
            $list = af_atf_kb_get_list_by_type_any($kbType);

            $html = '<div class="af-atf-kb-dynamic" data-character-field="' . htmlspecialchars_uni($fieldName) . '" data-mechanic-scope="' . htmlspecialchars_uni($mechanicScope) . '">';
            $html .= '<select class="select af-atf-input" name="' . $nameAttr . '"><option value=""></option>';
            $seen = [];
            foreach ($list as $item) {
                $key = (string)($item['key'] ?? '');
                if ($key === '') {
                    continue;
                }
                $seen[$key] = true;
                $selected = ($value === $key) ? ' selected="selected"' : '';
                $label = (string)($item['title'] ?? $key);
                $iconUrl = trim((string)($item['icon_url'] ?? ''));
                $iconClass = trim((string)($item['icon_class'] ?? ''));
                $tooltip = trim((string)($item['short'] ?? ''));
                $html .= '<option value="' . htmlspecialchars_uni($key) . '"'
                    . $selected
                    . ($iconUrl !== '' ? ' data-icon-url="' . htmlspecialchars_uni($iconUrl) . '"' : '')
                    . ($iconClass !== '' ? ' data-icon-class="' . htmlspecialchars_uni($iconClass) . '"' : '')
                    . ($tooltip !== '' ? ' data-tooltip="' . htmlspecialchars_uni($tooltip) . '"' : '')
                    . '>' . htmlspecialchars_uni($label) . '</option>';
            }
            if ($value !== '' && !isset($seen[$value])) {
                $fallbackLabel = af_atf_kb_resolve_dynamic_label($field, $value);
                $html .= '<option value="' . htmlspecialchars_uni($value) . '" selected="selected">' . htmlspecialchars_uni($fallbackLabel !== '' ? $fallbackLabel : $value) . '</option>';
            }
            $html .= '</select>';
            if ($fieldName === 'character_element') {
                $html .= '<div class="af-atf-kb-dynamic-preview" data-preview-role="element"></div>';
            }
            $html .= '</div>';
            return $html;
        }

        case 'kb_mechanic': {
            $provider = trim((string)($opts['provider'] ?? ''));
            $providers = [
                'dnd' => trim((string)($opts['dnd'] ?? '')),
                'arpg' => trim((string)($opts['arpg'] ?? '')),
            ];
            if ($provider !== '') {
                $providers['dnd'] = af_atf_character_provider_type($provider, 'dnd');
                $providers['arpg'] = af_atf_character_provider_type($provider, 'arpg');
            }
            $payload = ['dnd' => [], 'arpg' => []];
            foreach ($providers as $mode => $typeKey) {
                if ($typeKey === '') {
                    continue;
                }
                $payload[$mode] = af_atf_kb_get_list_by_type_any($typeKey);
            }

            $renderOptions = static function (array $items, string $selectedValue) use ($field): string {
                $html = '<option value=""></option>';
                $seen = [];
                foreach ($items as $item) {
                    $key = (string)($item['key'] ?? '');
                    if ($key === '') {
                        continue;
                    }
                    $seen[$key] = true;
                    $selected = ($selectedValue === $key) ? ' selected="selected"' : '';
                    $html .= '<option value="' . htmlspecialchars_uni($key) . '"' . $selected . '>' . htmlspecialchars_uni((string)($item['title'] ?? $key)) . '</option>';
                }
                if ($selectedValue !== '' && !isset($seen[$selectedValue])) {
                    $fallbackLabel = af_atf_kb_resolve_dynamic_label($field, $selectedValue);
                    $html .= '<option value="' . htmlspecialchars_uni($selectedValue) . '" selected="selected">' . htmlspecialchars_uni($fallbackLabel !== '' ? $fallbackLabel : $selectedValue) . '</option>';
                }
                return $html;
            };

            $initialOptions = $renderOptions((array)($payload[$activeMechanic] ?? []), $value);
            return '<div class="af-atf-kb-mechanic" data-character-field="' . htmlspecialchars_uni($fieldName) . '" data-options="' . htmlspecialchars_uni(json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}') . '">'
                . '<select class="select af-atf-input af-atf-kb-mechanic-select" name="' . $nameAttr . '">' . $initialOptions . '</select>'
                . '</div>';
        }

        case 'character_abilities': {
            $abilitySelects = af_atf_get_character_ability_select_payload();
            $optionsJson = json_encode($abilitySelects, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}';
            $optionsJson = str_replace('</', '<\/', $optionsJson);
            return '<div class="af-atf-abilities" data-max-items="8">'
                . '<input type="hidden" class="af-atf-abilities-hidden" name="' . $nameAttr . '" value="' . $safeValue . '" />'
                . '<script type="application/json" class="af-atf-abilities-options">' . $optionsJson . '</script>'
                . '<div class="af-atf-abilities-list"></div>'
                . '<button type="button" class="button af-atf-abilities-add">+ добавить способность</button>'
                . '</div>';
        }

        case 'character_stats': {
            $normalized = af_atf_normalize_character_stats_json($value);
            $decoded = json_decode($normalized, true);
            if (!is_array($decoded)) {
                $decoded = [];
            }
            $labels = af_atf_character_stats_labels();
            $percent = array_flip(af_atf_character_stats_percent_keys());

            $html = '<div class="af-atf-character-stats">';
            $html .= '<input type="hidden" class="af-atf-character-stats-hidden" name="' . $nameAttr . '" value="' . htmlspecialchars_uni($normalized) . '" />';
            $html .= '<div class="af-atf-character-stats-grid">';
            foreach (af_atf_character_stats_field_keys() as $key) {
                $label = htmlspecialchars_uni((string)($labels[$key] ?? $key));
                $val = '';
                if (array_key_exists($key, $decoded) && is_scalar($decoded[$key])) {
                    $val = (string)$decoded[$key];
                }
                $suffix = isset($percent[$key]) ? '<span class="af-atf-character-stats-suffix">%</span>' : '';
                $html .= '<label class="af-atf-character-stats-item">'
                    . '<span class="af-atf-character-stats-label">' . $label . '</span>'
                    . '<span class="af-atf-character-stats-control">'
                    . '<input type="number" step="0.01" class="textbox text_input af-atf-character-stats-input" data-key="' . htmlspecialchars_uni($key) . '" value="' . htmlspecialchars_uni($val) . '" />'
                    . $suffix
                    . '</span>'
                    . '</label>';
            }
            $html .= '</div></div>';
            return $html;
        }

        case 'sf_attributes_pointbuy': {
            $settings = af_atf_sf_pointbuy_get_settings();
            $curveJson = json_encode($settings['curve'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $costBase = isset($settings['curve']['base']) ? (int)$settings['curve']['base'] : (int)$settings['base'];
            $values = [];
            if ($value !== '') {
                $decoded = json_decode($value, true);
                if (is_array($decoded)) {
                    foreach (af_atf_sf_pointbuy_get_attr_codes() as $code) {
                        $values[$code] = isset($decoded[$code]) ? (int)$decoded[$code] : (int)$settings['base'];
                    }
                }
            }
            foreach (af_atf_sf_pointbuy_get_attr_codes() as $code) {
                if (!isset($values[$code])) {
                    $values[$code] = (int)$settings['base'];
                }
            }

            $hiddenJson = json_encode($values, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

            $dataAttrs = ' data-total="'.(int)$settings['total'].'"'
                . ' data-min="'.(int)$settings['min'].'"'
                . ' data-max="'.(int)$settings['max'].'"'
                . ' data-base="'.(int)$costBase.'"'
                . ' data-allow-negative="'.($settings['allow_negative'] ? 1 : 0).'"'
                . ' data-require-exact="'.($settings['require_exact'] ? 1 : 0).'"'
                . ' data-cost-curve="'.htmlspecialchars_uni($curveJson).'"';

            global $lang;
            $errOver = $lang->af_atf_sf_err_overbudget ?? 'Over budget';
            $errRange = $lang->af_atf_sf_err_out_of_range ?? 'Out of range';
            $errExact = $lang->af_atf_sf_err_not_exact ?? 'Points not exact';

            $dataAttrs .= ' data-err-overbudget="'.htmlspecialchars_uni($errOver).'"'
                . ' data-err-out-of-range="'.htmlspecialchars_uni($errRange).'"'
                . ' data-err-not-exact="'.htmlspecialchars_uni($errExact).'"';

            $totalLabel = htmlspecialchars_uni($lang->af_atf_sf_total_points ?? 'Total points');
            $spentLabel = htmlspecialchars_uni($lang->af_atf_sf_spent ?? 'Spent');
            $remainingLabel = htmlspecialchars_uni($lang->af_atf_sf_remaining ?? 'Remaining');

            $html = '<div class="af-atf-pointbuy"'.$dataAttrs.'>';
            $html .= '<input type="hidden" class="af-atf-pointbuy-hidden" name="'.$nameAttr.'" value="'.htmlspecialchars_uni($hiddenJson).'" />';

            $html .= '<div class="af-atf-pointbuy-table">';
            foreach (af_atf_sf_pointbuy_get_attr_groups() as $group) {
                $groupLabel = htmlspecialchars_uni((string)$group['label']);
                $html .= '<div class="af-atf-pointbuy-group">';
                $html .= '<div class="af-atf-pointbuy-group-title">'.$groupLabel.'</div>';
                foreach ($group['items'] as $item) {
                    $code = $item['code'];
                    $label = htmlspecialchars_uni($item['label'].' ('.$code.')');
                    $val = (int)($values[$code] ?? $settings['base']);
                    $html .= '<div class="af-atf-pointbuy-row">';
                    $html .= '<div class="af-atf-pointbuy-label">'.$label.'</div>';
                    $html .= '<div class="af-atf-pointbuy-control">'
                        . '<input type="number" class="text_input af-atf-pointbuy-input" data-attr="'.htmlspecialchars_uni($code).'"'
                        . ' value="'.(int)$val.'" min="'.(int)$settings['min'].'" max="'.(int)$settings['max'].'" />'
                        . '</div>';
                    $html .= '</div>';
                }
                $html .= '</div>';
            }
            $html .= '</div>';

            $html .= '<div class="af-atf-pointbuy-summary">'
                . '<div><span class="af-atf-pointbuy-summary-label">'.$totalLabel.':</span> <span class="af-atf-pointbuy-total">'.(int)$settings['total'].'</span></div>'
                . '<div><span class="af-atf-pointbuy-summary-label">'.$spentLabel.':</span> <span class="af-atf-pointbuy-spent">0</span></div>'
                . '<div><span class="af-atf-pointbuy-summary-label">'.$remainingLabel.':</span> <span class="af-atf-pointbuy-remaining">0</span></div>'
                . '</div>';
            $html .= '<div class="af-atf-pointbuy-errors" role="alert"></div>';
            $html .= '</div>';
            return $html;
        }

        case 'usernames': {
            // value хранится как "uid,uid,uid"
            $base = rtrim((string)$mybb->settings['bburl'], '/');
            $suggestUrl = $base.'/misc.php?action=af_atf_user_suggest';
            $resolveUrl = $base.'/misc.php?action=af_atf_user_resolve';

            $wrapId = 'af_atf_users_'.$fieldid;

            return ''
                . '<div id="'.$wrapId.'" class="af-atf-userchips"'
                . ' data-fieldid="'.$fieldid.'"'
                . ' data-suggest="'.htmlspecialchars_uni($suggestUrl).'"'
                . ' data-resolve="'.htmlspecialchars_uni($resolveUrl).'"'
                . ' data-max="'.(int)$maxUsers.'"'
                . '>'
                . '<input type="hidden" class="af-atf-userchips-hidden" name="'.$nameAttr.'" value="'.$safeValue.'" />'
                . '<div class="af-atf-userchips-box">'
                . '  <div class="af-atf-userchips-chips"></div>'
                . '  <input type="text" class="text_input af-atf-userchips-input"'
                . '    placeholder="Начни вводить ник..." autocomplete="off" spellcheck="false" />'
                . '</div>'
                . '<div class="af-atf-userchips-dd" hidden></div>'
                . '</div>';
        }

        case 'textarea': {
            $textarea = '<textarea id="'.$taId.'" class="textarea af-atf-input" name="'.$nameAttr.'" rows="8" cols="60"'.$maxAttr.'>'.$safeValue.'</textarea>';

            $toolbar = '';
            if (function_exists('build_mycode_inserter')) {
                $toolbar = build_mycode_inserter($taId, 'mini');
            }

            return '<div class="af-atf-editor">'.$toolbar.$textarea.'</div>';
        }

        case 'image':
            return '<input type="url" class="text_input af-atf-input" name="'.$nameAttr.'" value="'.$safeValue.'" placeholder="https://.../image.jpg"'.$maxAttr.' />';

        case 'select': {
            $html = '<select class="select af-atf-input" name="'.$nameAttr.'">';
            $html .= '<option value=""></option>';
            foreach ($opts as $k => $lbl) {
                $k2 = (string)$k;
                $sel = ((string)$value === $k2) ? ' selected="selected"' : '';
                $html .= '<option value="'.htmlspecialchars_uni($k2).'"'.$sel.'>'.htmlspecialchars_uni($lbl).'</option>';
            }
            $html .= '</select>';
            return $html;
        }

        case 'radio': {
            $html = '<div class="af-atf-radio">';
            foreach ($opts as $k => $lbl) {
                $k2 = (string)$k;
                $chk = ((string)$value === $k2) ? ' checked="checked"' : '';
                $id = 'af_atf_'.$fieldid.'_'.md5($k2);
                $html .= '<label for="'.$id.'"><input type="radio" id="'.$id.'" name="'.$nameAttr.'" value="'.htmlspecialchars_uni($k2).'"'.$chk.' /> '.htmlspecialchars_uni($lbl).'</label> ';
            }
            $html .= '</div>';
            return $html;
        }

        case 'checkbox': {
            $chk = ((string)$value === '1') ? ' checked="checked"' : '';
            return '<label><input type="checkbox" name="'.$nameAttr.'" value="1"'.$chk.' /> </label>';
        }

        case 'url':
            return '<input type="url" class="text_input af-atf-input" name="'.$nameAttr.'" value="'.$safeValue.'"'.$maxAttr.' />';

        case 'number':
            return '<input type="number" class="text_input af-atf-input" name="'.$nameAttr.'" value="'.$safeValue.'"'.$maxAttr.' />';

        case 'text':
        default:
            return '<input type="text" class="text_input af-atf-input" name="'.$nameAttr.'" value="'.$safeValue.'"'.$maxAttr.' />';
    }
}

function af_atf_parse_options(string $raw): array
{
    $raw = str_replace(["\r\n", "\r"], "\n", $raw);
    $lines = array_filter(array_map('trim', explode("\n", $raw)));

    $out = [];
    foreach ($lines as $line) {
        if (strpos($line, '=') !== false) {
            [$k, $v] = array_map('trim', explode('=', $line, 2));
            if ($k === '') {
                $k = $v;
            }
            $out[$k] = ($v === '') ? $k : $v;
        } else {
            $out[$line] = $line;
        }
    }
    return $out;
}

/* -------------------- KB HELPERS -------------------- */

function af_atf_kb_allowed_types(): array
{
    return ['race', 'class', 'theme'];
}

function af_atf_kb_is_ru(): bool
{
    if (function_exists('af_kb_is_ru')) {
        return af_kb_is_ru();
    }

    global $lang;
    return isset($lang->language) && $lang->language === 'russian';
}

function af_atf_kb_pick_text(array $row, string $field): string
{
    if (function_exists('af_kb_pick_text')) {
        return af_kb_pick_text($row, $field);
    }

    $suffix = af_atf_kb_is_ru() ? '_ru' : '_en';
    $key = $field . $suffix;
    $value = (string)($row[$key] ?? '');
    if ($value === '') {
        $fallback = (string)($row[$field . '_ru'] ?? '');
        if ($fallback === '') {
            $fallback = (string)($row[$field . '_en'] ?? '');
        }
        return $fallback;
    }

    return $value;
}

function af_atf_kb_get_list_by_type(string $type): array
{
    static $cacheListByType = [];

    $type = strtolower(trim($type));
    if (!in_array($type, af_atf_kb_allowed_types(), true)) {
        return [];
    }

    if (isset($cacheListByType[$type])) {
        return $cacheListByType[$type];
    }

    global $db;
    if (!is_object($db) || !$db->table_exists('af_kb_entries')) {
        $cacheListByType[$type] = [];
        return [];
    }

    $items = [];
    $q = $db->simple_select(
        'af_kb_entries',
        'type,`key`,title_ru,title_en,sortorder',
        "type='".$db->escape_string($type)."' AND active=1",
        ['order_by' => 'sortorder, title_ru, title_en', 'order_dir' => 'ASC']
    );
    while ($row = $db->fetch_array($q)) {
        $items[] = [
            'key' => (string)$row['key'],
            'title' => af_atf_kb_pick_text($row, 'title') ?: (string)$row['key'],
        ];
    }

    $cacheListByType[$type] = $items;
    return $items;
}

function af_atf_kb_get_entry(string $type, string $key): array
{
    static $cacheEntryByTypeKey = [];

    $type = strtolower(trim($type));
    $key = strtolower(trim($key));
    if ($type === '' || $key === '') {
        return [];
    }

    $cacheKey = $type . ':' . $key;
    if (isset($cacheEntryByTypeKey[$cacheKey])) {
        return $cacheEntryByTypeKey[$cacheKey];
    }

    global $db;
    if (!is_object($db) || !$db->table_exists('af_kb_entries')) {
        $cacheEntryByTypeKey[$cacheKey] = [];
        return [];
    }

    $row = $db->fetch_array($db->simple_select(
        'af_kb_entries',
        '*',
        "type='".$db->escape_string($type)."' AND `key`='".$db->escape_string($key)."' AND active=1",
        ['limit' => 1]
    ));

    if (!is_array($row) || empty($row)) {
        $cacheEntryByTypeKey[$cacheKey] = [];
        return [];
    }

    $cacheEntryByTypeKey[$cacheKey] = $row;
    return $row;
}

function af_atf_kb_resolve_label(string $optionsRaw, string $key): string
{
    if (function_exists('af_kb_resolve_atf_select_label')) {
        return af_kb_resolve_atf_select_label($optionsRaw, $key);
    }

    $opts = af_atf_parse_options($optionsRaw);
    return $opts[$key] ?? $key;
}

function af_atf_kb_build_chip(string $kbType, string $key, string $optionsRaw = ''): string
{
    $key = trim($key);
    if ($key === '') {
        return '';
    }

    $title = '';
    $entry = af_atf_kb_get_entry($kbType, $key);
    if (!empty($entry)) {
        $title = af_atf_kb_pick_text($entry, 'title');
    }

    if ($title === '') {
        $title = af_atf_kb_resolve_label($optionsRaw, $key);
    }

    $titleSafe = htmlspecialchars_uni($title);
    $keySafe = htmlspecialchars_uni($key);
    $typeSafe = htmlspecialchars_uni($kbType);

    return '<span class="af_kb_chip" data-kb-type="' . $typeSafe . '" data-kb-key="' . $keySafe . '">' . $titleSafe . '</span>';
}

/* -------------------- SF POINTBUY HELPERS -------------------- */

function af_atf_sf_pointbuy_default_curve(): array
{
    return [
        'mode' => 'step',
        'base' => 10,
        'costs' => [
            '10->11' => 1,
            '11->12' => 1,
            '12->13' => 2,
            '13->14' => 2,
            '14->15' => 3,
            '15->16' => 4,
            '16->17' => 5,
            '17->18' => 6,
        ],
    ];
}

function af_atf_sf_pointbuy_get_settings(): array
{
    global $mybb;

    $total = isset($mybb->settings['af_atf_sf_total_points'])
        ? (int)$mybb->settings['af_atf_sf_total_points']
        : 10;
    $minValue = isset($mybb->settings['af_atf_sf_min_value'])
        ? (int)$mybb->settings['af_atf_sf_min_value']
        : 8;
    $maxValue = isset($mybb->settings['af_atf_sf_max_value'])
        ? (int)$mybb->settings['af_atf_sf_max_value']
        : 18;
    $baseValue = isset($mybb->settings['af_atf_sf_base_value'])
        ? (int)$mybb->settings['af_atf_sf_base_value']
        : 10;

    $curveJson = (string)($mybb->settings['af_atf_sf_cost_curve'] ?? '');
    $curve = json_decode($curveJson, true);
    if (!is_array($curve)) {
        $curve = af_atf_sf_pointbuy_default_curve();
    }

    return [
        'total' => $total,
        'min' => $minValue,
        'max' => $maxValue,
        'base' => $baseValue,
        'curve' => $curve,
        'allow_negative' => !empty($mybb->settings['af_atf_sf_allow_negative_remaining']),
        'require_exact' => !empty($mybb->settings['af_atf_sf_require_exact_spend']),
    ];
}

function af_atf_sf_pointbuy_get_attr_groups(): array
{
    global $lang;

    $attrLabel = static function(string $key, string $fallback) use ($lang): string {
        if (!empty($lang->{$key})) {
            return (string)$lang->{$key};
        }
        return $fallback;
    };

    return [
        [
            'label' => $lang->af_atf_group_mental ?? ($lang->af_atf_sf_group_mental ?? 'Mental Group'),
            'items' => [
                ['code' => 'INT', 'label' => $attrLabel('af_atf_attr_int', 'Intelligence')],
                ['code' => 'WILL', 'label' => $attrLabel('af_atf_attr_will', 'Willpower')],
                ['code' => 'PRE', 'label' => $attrLabel('af_atf_attr_pre', 'Presence')],
            ],
        ],
        [
            'label' => $lang->af_atf_group_combat ?? ($lang->af_atf_sf_group_combat ?? 'Combat Group'),
            'items' => [
                ['code' => 'TECH', 'label' => $attrLabel('af_atf_attr_tech', 'Technique')],
                ['code' => 'REF', 'label' => $attrLabel('af_atf_attr_ref', 'Reflexes')],
                ['code' => 'DEX', 'label' => $attrLabel('af_atf_attr_dex', 'Dexterity')],
            ],
        ],
        [
            'label' => $lang->af_atf_group_physical ?? ($lang->af_atf_sf_group_physical ?? 'Physical Group'),
            'items' => [
                ['code' => 'CON', 'label' => $attrLabel('af_atf_attr_con', 'Constitution')],
                ['code' => 'STR', 'label' => $attrLabel('af_atf_attr_str', 'Strength')],
                ['code' => 'BODY', 'label' => $attrLabel('af_atf_attr_body', 'Body')],
            ],
        ],
    ];
}

function af_atf_sf_pointbuy_get_attr_codes(): array
{
    $codes = [];
    foreach (af_atf_sf_pointbuy_get_attr_groups() as $group) {
        foreach ($group['items'] as $item) {
            $codes[] = $item['code'];
        }
    }
    return $codes;
}

function af_atf_sf_pointbuy_step_cost(int $from, int $to, array $curve): ?int
{
    $key = $from . '->' . $to;
    if (isset($curve['costs']) && is_array($curve['costs']) && array_key_exists($key, $curve['costs'])) {
        return (int)$curve['costs'][$key];
    }
    return null;
}

function af_atf_sf_pointbuy_calc_cost(int $value, array $curve, int $base): ?int
{
    if ($value === $base) {
        return 0;
    }

    $cost = 0;
    if ($value > $base) {
        for ($i = $base; $i < $value; $i++) {
            $stepCost = af_atf_sf_pointbuy_step_cost($i, $i + 1, $curve);
            if ($stepCost === null) {
                return null;
            }
            $cost += $stepCost;
        }
        return $cost;
    }

    for ($i = $base; $i > $value; $i--) {
        $stepCost = af_atf_sf_pointbuy_step_cost($i - 1, $i, $curve);
        if ($stepCost === null) {
            return null;
        }
        $cost -= $stepCost;
    }

    return $cost;
}

function af_atf_sf_pointbuy_normalize(string $raw, array $settings, ?string &$errorKey = null): array
{
    $errorKey = null;

    $raw = trim($raw);
    if ($raw === '') {
        return ['ok' => false, 'json' => '', 'values' => []];
    }

    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        $errorKey = 'af_atf_sf_err_out_of_range';
        return ['ok' => false, 'json' => '', 'values' => []];
    }

    $values = [];
    foreach (af_atf_sf_pointbuy_get_attr_codes() as $code) {
        $values[$code] = isset($decoded[$code]) ? (int)$decoded[$code] : (int)$settings['base'];
    }

    foreach ($values as $val) {
        if ($val < (int)$settings['min'] || $val > (int)$settings['max']) {
            $errorKey = 'af_atf_sf_err_out_of_range';
            return ['ok' => false, 'json' => '', 'values' => $values];
        }
    }

    $curve = is_array($settings['curve']) ? $settings['curve'] : af_atf_sf_pointbuy_default_curve();
    $base = isset($curve['base']) ? (int)$curve['base'] : (int)$settings['base'];

    $spent = 0;
    foreach ($values as $val) {
        $cost = af_atf_sf_pointbuy_calc_cost($val, $curve, $base);
        if ($cost === null) {
            $errorKey = 'af_atf_sf_err_out_of_range';
            return ['ok' => false, 'json' => '', 'values' => $values];
        }
        $spent += $cost;
    }

    if (!$settings['allow_negative'] && $spent > (int)$settings['total']) {
        $errorKey = 'af_atf_sf_err_overbudget';
        return ['ok' => false, 'json' => '', 'values' => $values];
    }

    if ($settings['require_exact'] && $spent !== (int)$settings['total']) {
        $errorKey = 'af_atf_sf_err_not_exact';
        return ['ok' => false, 'json' => '', 'values' => $values];
    }

    $normalized = [];
    foreach (af_atf_sf_pointbuy_get_attr_codes() as $code) {
        $normalized[$code] = $values[$code];
    }

    $json = json_encode($normalized, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    return ['ok' => true, 'json' => $json, 'values' => $normalized, 'spent' => $spent];
}

/* -------------------- VALUES: LOAD/SAVE -------------------- */

function af_atf_get_values_by_tid(int $tid): array
{
    global $db;

    if ($tid <= 0 || !$db->table_exists(AF_ATF_TABLE_VALUES)) {
        return [];
    }

    $vals = [];
    $q = $db->simple_select(AF_ATF_TABLE_VALUES, 'fieldid,value', "tid=".(int)$tid);
    while ($row = $db->fetch_array($q)) {
        $vals[(int)$row['fieldid']] = (string)$row['value'];
    }
    return $vals;
}

function af_atf_save_values(int $tid, array $fieldValues): void
{
    global $db;

    if ($tid <= 0 || !$db->table_exists(AF_ATF_TABLE_VALUES)) {
        return;
    }

    foreach ($fieldValues as $fieldid => $value) {
        $fieldid = (int)$fieldid;
        if ($fieldid <= 0) {
            continue;
        }

        $value = is_string($value) ? trim($value) : '';
        if ($value === '') {
            $db->delete_query(AF_ATF_TABLE_VALUES, "tid=".(int)$tid." AND fieldid=".(int)$fieldid);
            continue;
        }

        $db->write_query("
            INSERT INTO `".TABLE_PREFIX.AF_ATF_TABLE_VALUES."` (tid, fieldid, value)
            VALUES (".(int)$tid.", ".(int)$fieldid.", '".$db->escape_string($value)."')
            ON DUPLICATE KEY UPDATE value=VALUES(value)
        ");
    }
}

/* -------------------- DATAHANDLER HOOKS -------------------- */
function af_atf_force_min_message_if_needed(int $fid): void
{
    global $mybb;

    if ($fid <= 0) {
        return;
    }

    // если в этом форуме нет ATF-полей — ничего не делаем
    $fields = af_atf_get_fields_for_forum($fid);
    if (empty($fields)) {
        return;
    }

    $msg = $mybb->get_input('message');
    $msg = is_string($msg) ? $msg : '';

    // РОВНО "ㅤㅤ⠀" (U+3164, U+3164, U+2800)
    $inv = "\u{3164}\u{3164}\u{2800}";

    // считаем пустым, если там только пробелы/переводы строк/наши невидимые символы
    $check = str_replace(["\u{200B}", "\u{3164}", "\u{2800}"], '', $msg);
    if (trim($check) === '') {
        $mybb->input['message'] = $inv;
        $_POST['message'] = $inv;
    }
}

function af_atf_character_active_mechanic(): string
{
    $fid = (int)($GLOBALS['af_atf_context_fid'] ?? 0);
    $fields = is_array($GLOBALS['af_atf_context_fields'] ?? null) ? (array)$GLOBALS['af_atf_context_fields'] : [];
    $values = is_array($GLOBALS['af_atf_context_values'] ?? null) ? (array)$GLOBALS['af_atf_context_values'] : [];
    return af_atf_character_resolve_active_mechanic($fid, $fields, $values);
}

function af_atf_character_stats_field_keys(): array
{
    return [
        'character_hp',
        'character_defense',
        'character_attack_power',
        'character_element_damage_bonus',
        'character_crit_damage',
        'character_healing_received_bonus',
        'character_elemental_mastery',
        'character_healing_bonus',
        'character_shield_strength',
        'character_luck',
    ];
}

function af_atf_character_stats_percent_keys(): array
{
    return [
        'character_element_damage_bonus',
        'character_crit_damage',
        'character_healing_received_bonus',
        'character_healing_bonus',
        'character_shield_strength',
        'character_luck',
    ];
}

function af_atf_character_stats_labels(): array
{
    return [
        'character_hp' => 'HP',
        'character_defense' => 'Защита',
        'character_attack_power' => 'Сила атаки',
        'character_element_damage_bonus' => 'Бонус урона стихий',
        'character_crit_damage' => 'Крит. урон',
        'character_healing_received_bonus' => 'Бонус входящего лечения',
        'character_elemental_mastery' => 'Мастерство стихий',
        'character_healing_bonus' => 'Бонус лечения',
        'character_shield_strength' => 'Сила щита',
        'character_luck' => 'Удача',
    ];
}

function af_atf_character_ability_display_label(string $setKey, string $value): string
{
    $value = trim($value);
    if ($value === '') {
        return '';
    }

    if (function_exists('af_kb_get_arpg_mechanics_option_label')) {
        $label = trim((string)af_kb_get_arpg_mechanics_option_label($setKey, $value, true));
        if ($label !== '') {
            return $label;
        }
    }

    return $value;
}

function af_atf_normalize_character_stats_json(string $raw): string
{
    $raw = trim($raw);
    if ($raw === '') {
        return '{}';
    }

    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        return '{}';
    }

    $normalized = [];
    foreach (af_atf_character_stats_field_keys() as $key) {
        if (!array_key_exists($key, $decoded)) {
            continue;
        }
        $val = $decoded[$key];
        if (!is_scalar($val) || $val === '') {
            continue;
        }

        if (is_numeric($val)) {
            $num = (float)$val;
            $normalized[$key] = ((float)(int)$num === $num) ? (int)$num : round($num, 2);
            continue;
        }

        $clean = trim((string)$val);
        if ($clean === '') {
            continue;
        }
        $clean = str_replace(',', '.', $clean);
        if (!is_numeric($clean)) {
            continue;
        }
        $num = (float)$clean;
        $normalized[$key] = ((float)(int)$num === $num) ? (int)$num : round($num, 2);
    }

    return json_encode($normalized, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}';
}

function af_atf_normalize_character_abilities_json(string $raw): string
{
    $raw = trim($raw);
    if ($raw === '') {
        return '[]';
    }

    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        return '[]';
    }

    $out = [];
    foreach ($decoded as $row) {
        if (!is_array($row)) {
            continue;
        }
        $name = trim((string)($row['ability_name'] ?? $row['name'] ?? ''));
        $type = trim((string)($row['type'] ?? $row['ability_type'] ?? 'active'));
        $description = trim((string)($row['ability_description'] ?? ''));
        $kbKey = trim((string)($row['ability_kb_key'] ?? ''));
        $sortorder = (int)($row['sortorder'] ?? (count($out) + 1));
        $iconUrl = trim((string)($row['icon_url'] ?? ''));

        if ($name === '' && $description === '' && $kbKey === '' && $iconUrl === '') {
            continue;
        }

        $type = strtolower($type);
        if ($type === '') {
            $type = 'active';
        }
        if ($sortorder < 0) {
            $sortorder = 0;
        }

        $normalized = [
            'slot_index' => max(1, (int)($row['slot_index'] ?? ($sortorder > 0 ? $sortorder : (count($out) + 1)))),
            'ability_name' => my_substr($name, 0, 255),
            'icon_url' => my_substr($iconUrl, 0, 500),
            'type' => $type,
            'ability_type' => $type,
            'subtype' => my_substr(trim((string)($row['subtype'] ?? '')), 0, 128),
            'slot' => my_substr(trim((string)($row['slot'] ?? '')), 0, 128),
            'damage_type' => my_substr(trim((string)($row['damage_type'] ?? '')), 0, 128),
            'targeting' => my_substr(trim((string)($row['targeting'] ?? '')), 0, 128),
            'range' => my_substr(trim((string)($row['range'] ?? '')), 0, 64),
            'damage_value' => my_substr(trim((string)($row['damage_value'] ?? '')), 0, 64),
            'shield_value' => my_substr(trim((string)($row['shield_value'] ?? '')), 0, 64),
            'heal_value' => my_substr(trim((string)($row['heal_value'] ?? '')), 0, 64),
            'ability_description' => my_substr($description, 0, 5000),
            'ability_kb_key' => my_substr($kbKey, 0, 128),
            'sortorder' => $sortorder,
        ];

        if ($normalized['damage_value'] === '' || $normalized['shield_value'] === '' || $normalized['heal_value'] === '') {
            $effects = is_array($row['effects'] ?? null) ? (array)$row['effects'] : [];
            foreach ($effects as $effect) {
                if (!is_array($effect)) {
                    continue;
                }
                $kind = trim((string)($effect['kind'] ?? ''));
                if (!array_key_exists('value', $effect) || !is_scalar($effect['value'])) {
                    continue;
                }
                $value = trim((string)$effect['value']);
                if ($kind === 'shield' && $normalized['shield_value'] === '') {
                    $normalized['shield_value'] = my_substr($value, 0, 64);
                } elseif ($kind === 'heal' && $normalized['heal_value'] === '') {
                    $normalized['heal_value'] = my_substr($value, 0, 64);
                } elseif ($kind === 'damage' && $normalized['damage_value'] === '') {
                    $normalized['damage_value'] = my_substr($value, 0, 64);
                }
                if ($normalized['damage_value'] !== '' && $normalized['shield_value'] !== '' && $normalized['heal_value'] !== '') {
                    break;
                }
            }
        }

        $out[] = $normalized;
        if (count($out) >= 8) {
            break;
        }
    }

    return json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '[]';
}

function af_atf_newthread_do_start(): void
{
    global $fid, $mybb;

    $fidI = (int)($fid ?? 0);
    if ($fidI <= 0 && isset($mybb) && is_object($mybb)) {
        $fidI = (int)$mybb->get_input('fid', MyBB::INPUT_INT);
    }

    if ($fidI > 0) {
        $fid = $fidI;
    }

    // чтобы при preview/ошибках поля НЕ СТИРАЛИСЬ — готовим блок из POST
    af_atf_prepare_input_block($fidI, 0, false);

    // страховка пустого message
    af_atf_force_min_message_if_needed($fidI);
}


function af_atf_editpost_do_start(): void
{
    global $fid, $tid, $pid, $mybb;

    $pidI = (int)($pid ?? 0);
    if ($pidI <= 0 && isset($mybb) && is_object($mybb)) {
        $pidI = (int)$mybb->get_input('pid', MyBB::INPUT_INT);
    }

    if ($pidI <= 0) {
        return;
    }

    $ctx = af_atf_get_post_context_by_pid($pidI);
    if (empty($ctx['is_first'])) {
        return;
    }

    $fidI = (int)($ctx['fid'] ?? 0);
    $tidI = (int)($ctx['tid'] ?? 0);

    if ($fidI > 0) { $fid = $fidI; }
    if ($tidI > 0) { $tid = $tidI; }

    // чтобы при preview/ошибках поля НЕ СТИРАЛИСЬ — готовим блок из POST
    af_atf_prepare_input_block($fidI, $tidI, true);

    // страховка пустого message
    af_atf_force_min_message_if_needed($fidI);
}


function af_atf_newthread_do_end(): void
{
    global $mybb, $fid, $tid;

    $fidI = (int)($fid ?? 0);
    $tidI = (int)($tid ?? 0);

    if ($fidI <= 0 || $tidI <= 0) {
        return;
    }

    $fields = af_atf_get_fields_for_forum($fidI);
    if (empty($fields)) {
        return;
    }

    $values = af_atf_collect_posted_values($fields);
    $GLOBALS['af_atf_context_fid'] = $fidI;
    $GLOBALS['af_atf_context_fields'] = $fields;
    $GLOBALS['af_atf_context_values'] = $values;

    // лёгкая нормализация (основная валидация уже прошла в DataHandler)
    $activeMechanic = af_atf_character_active_mechanic();
    foreach ($fields as $f) {
        $fieldid = (int)$f['fieldid'];
        if ($fieldid <= 0) {
            continue;
        }

        $val = isset($values[$fieldid]) ? (string)$values[$fieldid] : '';
        $val = trim($val);

        $maxlen = (int)$f['maxlen'];
        if ($maxlen > 0 && my_strlen($val) > $maxlen) {
            $val = my_substr($val, 0, $maxlen);
        }

        $type = (string)$f['type'];
        $fieldName = (string)($f['name'] ?? '');
        if ($activeMechanic === 'dnd' && ($fieldName === 'character_element' || $fieldName === 'character_faction')) {
            $values[$fieldid] = '';
            continue;
        }

        if ($type === 'kb_race' || $type === 'kb_class' || $type === 'kb_theme') {
            if (!preg_match('/^[a-z0-9_-]{2,64}$/i', $val)) {
                $val = '';
            } elseif ($val !== '') {
                $kbTypeMap = ['kb_race' => 'race', 'kb_class' => 'class', 'kb_theme' => 'theme'];
                $kbType = $kbTypeMap[$type] ?? '';
                if ($kbType === '') {
                    $val = '';
                    $values[$fieldid] = $val;
                    continue;
                }
                $list = af_atf_kb_get_list_by_type($kbType);
                $known = [];
                foreach ($list as $item) {
                    $known[(string)($item['key'] ?? '')] = true;
                }
                $opts = af_atf_parse_options((string)$f['options']);
                if (empty($known[$val]) && !array_key_exists($val, $opts)) {
                    $val = '';
                }
            }
        } elseif ($type === 'sf_attributes_pointbuy') {
            $settings = af_atf_sf_pointbuy_get_settings();
            $errorKey = null;
            $normalized = af_atf_sf_pointbuy_normalize($val, $settings, $errorKey);
            $val = !empty($normalized['ok']) ? (string)$normalized['json'] : '';
        } elseif ($type === 'character_stats') {
            $val = af_atf_normalize_character_stats_json($val);
        } elseif ($type === 'character_abilities') {
            $val = af_atf_normalize_character_abilities_json($val);
        } elseif ($type === 'kb_dynamic' || $type === 'kb_mechanic') {
            if (!preg_match('/^[a-z0-9_-]{2,64}$/i', $val)) {
                $val = '';
            }
        }

        if (($type === 'select' || $type === 'radio') && $val !== '') {
            $opts = af_atf_parse_options((string)$f['options']);
            if (!array_key_exists($val, $opts)) {
                $val = '';
            }
        }

        $values[$fieldid] = $val;
    }

    af_atf_save_values($tidI, $values);
    af_atf_character_bridge_store_thread_kb_link($tidI, $fidI, (int)($mybb->user['uid'] ?? 0));
}

function af_atf_handle_character_kb_bridge_action(bool $syncOnly): void
{
    global $mybb, $db, $lang;

    verify_post_check($mybb->get_input('my_post_key'));
    $tid = (int)$mybb->get_input('tid');
    if ($tid <= 0) {
        error_no_permission();
    }

    $thread = $db->fetch_array($db->simple_select('threads', '*', 'tid=' . $tid, ['limit' => 1]));
    if (empty($thread)) {
        error_no_permission();
    }

    $fid = (int)($thread['fid'] ?? 0);
    if (!function_exists('af_charactersheets_user_can_accept') || !af_charactersheets_user_can_accept($mybb->user ?? [], $fid)) {
        error_no_permission();
    }

    $result = af_atf_bridge_sync_character_kb_from_thread($tid, $thread, [
        'accepted_by_uid' => (int)($mybb->user['uid'] ?? 0),
        'source' => $syncOnly ? 'manual_sync_button' : 'manual_create_button',
        'sync_only' => $syncOnly,
        'require_existing' => $syncOnly,
    ]);

    $showThreadUrl = 'showthread.php?tid=' . $tid;
    if (empty($result['ok'])) {
        $msg = $syncOnly
            ? ($lang->af_charactersheets_kb_sync_error ?? 'Не удалось синхронизировать KB-запись.')
            : ($lang->af_charactersheets_kb_create_error ?? 'Не удалось создать запись KB.');
        redirect($showThreadUrl, $msg . ' (' . htmlspecialchars_uni((string)($result['reason'] ?? 'unknown')) . ')');
    }

    $entryId = (int)($result['entry_id'] ?? 0);
    $entryUrl = $showThreadUrl;
    if ($entryId > 0 && function_exists('af_charactersheets_build_kb_entry_url')) {
        $entryUrl = af_charactersheets_build_kb_entry_url($entryId, $tid);
    }
    if ($syncOnly) {
        $msg = $lang->af_charactersheets_kb_sync_done ?? 'KB-запись синхронизирована с анкетой.';
    } else {
        $mode = (string)($result['mode'] ?? 'create');
        $msg = $mode === 'update'
            ? ($lang->af_charactersheets_kb_create_exists ?? 'KB-запись уже существовала, связь обновлена.')
            : ($lang->af_charactersheets_kb_create_done ?? 'KB-запись персонажа создана.');
    }

    redirect($entryUrl, $msg);
}

function af_atf_character_bridge_store_thread_kb_link(int $tid, int $fid, int $uid): void
{
    global $db;

    if ($tid <= 0 || !is_object($db) || !$db->table_exists('af_kb_entries')) {
        return;
    }
    if (!function_exists('af_charactersheets_upsert_accept_row')) {
        return;
    }

    $entry = (array)($GLOBALS['af_atf_prefill_kb_entry'] ?? []);
    if ((int)($entry['id'] ?? 0) > 0 && (string)($entry['type'] ?? '') === 'character') {
        af_charactersheets_upsert_accept_row($tid, [
            'uid' => max(0, $uid),
            'kb_entry_id' => (int)$entry['id'],
        ]);
        return;
    }
}

function af_atf_bridge_sync_character_kb_from_thread(int $tid, array $thread = [], array $context = []): array
{
    global $db;

    $result = ['ok' => false, 'entry_id' => 0, 'mode' => 'skip', 'reason' => ''];
    if ($tid <= 0) {
        $result['reason'] = 'invalid_tid';
        return $result;
    }
    if (!$db->table_exists('af_kb_entries')) {
        $result['reason'] = 'kb_unavailable';
        return $result;
    }

    if (empty($thread)) {
        $thread = $db->fetch_array($db->simple_select('threads', '*', 'tid=' . $tid, ['limit' => 1]));
    }
    if (!$thread || !is_array($thread)) {
        $result['reason'] = 'thread_not_found';
        return $result;
    }

    $fields = function_exists('af_charactersheets_get_atf_fields') ? af_charactersheets_get_atf_fields($tid) : [];
    $index = function_exists('af_charactersheets_index_fields') ? af_charactersheets_index_fields($fields) : [];
    if (empty($index)) {
        $result['reason'] = 'atf_fields_empty';
        return $result;
    }

    $entryLink = [];
    if (function_exists('af_charactersheets_get_accept_row')) {
        $accept = af_charactersheets_get_accept_row($tid);
        $entryId = (int)($accept['kb_entry_id'] ?? 0);
        if ($entryId > 0) {
            $entryLink = $db->fetch_array($db->simple_select('af_kb_entries', '*', 'id=' . $entryId . " AND type='character'", ['limit' => 1]));
        }
    }
    if (!$entryLink) {
        $prefillEntry = (array)($GLOBALS['af_atf_prefill_kb_entry'] ?? []);
        if ((int)($prefillEntry['id'] ?? 0) > 0) {
            $entryLink = $db->fetch_array($db->simple_select('af_kb_entries', '*', 'id=' . (int)$prefillEntry['id'] . " AND type='character'", ['limit' => 1]));
        }
    }

    $isCanon = false;
    if (!empty($entryLink['id'])) {
        $decoded = function_exists('af_charactersheets_extract_character_contract_payload')
            ? af_charactersheets_extract_character_contract_payload((array)$entryLink)
            : [];
        $category = trim((string)(($decoded['profile'] ?? [])['category'] ?? ''));
        $isCanon = ($category === 'canons');
    }

    if ($isCanon) {
        $entryId = (int)$entryLink['id'];
        if ($entryId > 0 && function_exists('af_charactersheets_upsert_accept_row')) {
            af_charactersheets_upsert_accept_row($tid, ['uid' => (int)($thread['uid'] ?? 0), 'kb_entry_id' => $entryId]);
            $result['ok'] = true;
            $result['entry_id'] = $entryId;
            $result['mode'] = 'link';
            return $result;
        }
    }

    if (!function_exists('af_charactersheets_pick_field_value')) {
        $result['reason'] = 'charactersheets_helpers_missing';
        return $result;
    }

    $entryKey = 'oc-' . $tid;
    $titleRu = af_charactersheets_pick_field_value($index, ['character_name_ru', 'character_name', 'name', 'char_name']);
    $titleEn = af_charactersheets_pick_field_value($index, ['character_name', 'character_name_en', 'name', 'char_name']);
    $subject = trim((string)($thread['subject'] ?? ''));
    if ($titleRu === '') {
        $titleRu = $subject !== '' ? $subject : ('OC #' . $tid);
    }
    if ($titleEn === '') {
        $titleEn = $titleRu;
    }

    $profile = [
        'category' => 'originals',
        'character_pic' => af_charactersheets_get_portrait_url($index),
        'character_prototype' => af_charactersheets_pick_field_value($index, ['character_prototype']),
        'character_name' => $titleEn,
        'character_name_ru' => $titleRu,
        'character_nicknames' => af_charactersheets_pick_field_value($index, ['character_nicknames', 'character_nickname']),
        'character_element' => af_charactersheets_pick_field_value($index, ['character_element', 'element']),
        'character_gen' => af_charactersheets_pick_field_value($index, ['character_gen', 'character_gender', 'gender']),
        'character_race' => af_charactersheets_pick_field_value($index, ['character_race', 'race']),
        'character_origin' => af_charactersheets_pick_field_value($index, ['character_origin']),
        'character_class' => af_charactersheets_pick_field_value($index, ['character_class', 'class']),
        'character_faction' => af_charactersheets_pick_field_value($index, ['character_faction', 'faction']),
        'character_app' => af_charactersheets_pick_field_value($index, ['character_app', 'character_about', 'character_bio', 'character_description', 'app']),
    ];
    $stats = af_atf_bridge_extract_oc_stats($index);
    $abilities = af_atf_bridge_extract_oc_abilities($index);

    $rules = [
        'schema' => 'af_kb.character.contract.v1',
        'type_profile' => 'character',
        'version' => '1.0',
        'character_profile' => $profile,
        'character_stats' => $stats,
        'character_abilities' => $abilities,
        'character_links' => [],
        'character_meta' => [
            'contract' => 'af_kb.character.contract.v1',
            'contract_version' => '1.0',
            'source' => 'af_atf_bridge',
            'sync_source' => (string)($context['source'] ?? 'unknown'),
            'accepted_by_uid' => (int)($context['accepted_by_uid'] ?? 0),
            'source_tid' => (int)$tid,
            'source_uid' => (int)($thread['uid'] ?? 0),
            'synced_at' => TIME_NOW,
        ],
    ];

    $rulesJson = json_encode($rules, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (!is_string($rulesJson) || $rulesJson === '') {
        $result['reason'] = 'rules_encode_failed';
        return $result;
    }

    $existing = $db->fetch_array(
        $db->simple_select('af_kb_entries', '*', "type='character' AND `key`='" . $db->escape_string($entryKey) . "'", ['limit' => 1])
    );
    if (!empty($context['require_existing']) && (!$existing || empty($existing['id']))) {
        $result['reason'] = 'existing_entry_not_found';
        return $result;
    }

    $entryPayload = [
        'type' => 'character',
        'key' => $entryKey,
        'title_ru' => $titleRu,
        'title_en' => $titleEn,
        'short_ru' => (string)$profile['character_app'],
        'short_en' => (string)$profile['character_app'],
        'body_ru' => '',
        'body_en' => '',
        'tech_ru' => '{}',
        'tech_en' => '{}',
        'meta_json' => json_encode(['rules' => $rules], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}',
        'data_json' => $rulesJson,
        'icon_class' => '',
        'icon_url' => '',
        'banner_url' => '',
        'bg_url' => '',
        'item_kind' => '',
        'active' => 1,
        'sortorder' => 0,
        'updated_at' => TIME_NOW,
    ];

    if ($existing && !empty($existing['id'])) {
        $db->update_query('af_kb_entries', af_atf_bridge_db_escape_array($entryPayload), 'id=' . (int)$existing['id']);
        $entryId = (int)$existing['id'];
        $result['mode'] = 'update';
    } else {
        $entryId = (int)$db->insert_query('af_kb_entries', af_atf_bridge_db_escape_array($entryPayload));
        $result['mode'] = 'create';
    }
    if ($entryId <= 0) {
        $result['reason'] = 'save_failed';
        return $result;
    }

    af_atf_bridge_assign_kb_originals_category($entryId);
    if (function_exists('af_charactersheets_upsert_accept_row')) {
        af_charactersheets_upsert_accept_row($tid, [
            'uid' => (int)($thread['uid'] ?? 0),
            'kb_entry_id' => $entryId,
            'kb_synced_at' => TIME_NOW,
        ]);
    }

    $result['ok'] = true;
    $result['entry_id'] = $entryId;
    return $result;
}

function af_atf_bridge_db_escape_array(array $data): array
{
    global $db;
    $out = [];
    foreach ($data as $k => $v) {
        if ($v === null) {
            $out[$k] = '';
            continue;
        }
        if (is_bool($v)) {
            $out[$k] = $v ? 1 : 0;
            continue;
        }
        if (is_int($v) || is_float($v)) {
            $out[$k] = $v;
            continue;
        }
        $out[$k] = $db->escape_string((string)$v);
    }
    return $out;
}

function af_atf_bridge_assign_kb_originals_category(int $entryId): void
{
    global $db;
    if ($entryId <= 0 || !$db->table_exists('af_kb_categories')) {
        return;
    }
    $cat = $db->fetch_array($db->simple_select('af_kb_categories', 'cat_id', "type='character' AND `key`='originals' AND active=1", ['limit' => 1]));
    $catId = (int)($cat['cat_id'] ?? 0);
    if ($catId <= 0) {
        return;
    }
    if (function_exists('af_kb_entry_set_categories')) {
        af_kb_entry_set_categories($entryId, [$catId], $catId);
        return;
    }
}

function af_atf_bridge_extract_oc_abilities(array $index): array
{
    $abilities = [];
    $jsonRaw = af_charactersheets_pick_field_value($index, ['character_abilities', 'abilities', 'arpg_abilities', 'character_skills'], false);
    if ($jsonRaw !== '') {
        $decoded = function_exists('af_charactersheets_json_decode') ? af_charactersheets_json_decode($jsonRaw) : (array)json_decode($jsonRaw, true);
        $rows = [];
        if (isset($decoded[0]) && is_array($decoded[0])) {
            $rows = $decoded;
        } elseif (is_array($decoded['items'] ?? null)) {
            $rows = (array)$decoded['items'];
        } elseif (is_array($decoded['abilities'] ?? null)) {
            $rows = (array)$decoded['abilities'];
        } elseif (is_array($decoded)) {
            $rows = array_values(array_filter($decoded, 'is_array'));
        }
        foreach ($rows as $idx => $ability) {
            if (!is_array($ability)) {
                continue;
            }
            $name = trim((string)($ability['ability_name'] ?? $ability['name'] ?? $ability['title'] ?? ''));
            $description = trim((string)($ability['ability_description'] ?? $ability['description'] ?? $ability['desc'] ?? ''));
            $kbKey = trim((string)($ability['ability_kb_key'] ?? $ability['ability_key'] ?? $ability['kb_key'] ?? $ability['key'] ?? ''));
            if ($name === '' && $description === '' && $kbKey === '') {
                continue;
            }
            $abilities[] = [
                'slot_index' => max(1, (int)($ability['slot_index'] ?? ($idx + 1))),
                'ability_name' => $name,
                'icon_url' => trim((string)($ability['icon_url'] ?? '')),
                'type' => trim((string)($ability['type'] ?? 'active')) === 'passive' ? 'passive' : 'active',
                'ability_description' => $description,
                'ability_kb_key' => $kbKey,
                'sortorder' => (int)($ability['sortorder'] ?? ($idx + 1)),
            ];
        }
    }
    return $abilities;
}

function af_atf_bridge_extract_oc_stats(array $index): array
{
    $aliases = [
        'character_hp' => ['character_hp', 'hp', 'health'],
        'character_defense' => ['character_defense', 'defense', 'def'],
        'character_element_damage_bonus' => ['character_element_damage_bonus', 'element_damage_bonus'],
        'character_crit_damage' => ['character_crit_damage', 'crit_damage'],
        'character_healing_received_bonus' => ['character_healing_received_bonus', 'healing_received_bonus'],
        'character_attack_power' => ['character_attack_power', 'attack_power', 'atk'],
        'character_elemental_mastery' => ['character_elemental_mastery', 'elemental_mastery'],
        'character_healing_bonus' => ['character_healing_bonus', 'healing_bonus'],
        'character_shield_strength' => ['character_shield_strength', 'shield_strength'],
        'character_luck' => ['character_luck', 'luck'],
    ];
    $stats = array_fill_keys(array_keys($aliases), 0);
    foreach ($aliases as $targetKey => $keys) {
        $raw = af_charactersheets_pick_field_value($index, $keys, false);
        $num = function_exists('af_charactersheets_to_number') ? af_charactersheets_to_number($raw) : (is_numeric($raw) ? (float)$raw : null);
        if ($num !== null) {
            $stats[$targetKey] = $num;
        }
    }
    return $stats;
}

function af_atf_editpost_do_end(): void
{
    global $fid, $tid, $pid, $post, $thread;

    $pidI = (int)($pid ?? 0);
    if (!af_atf_is_first_post_context(is_array($post) ? $post : [], $pidI)) {
        return;
    }

    // fid может быть пустым
    $fidI = 0;
    if (!empty($fid)) {
        $fidI = (int)$fid;
    } elseif (is_array($post) && !empty($post['fid'])) {
        $fidI = (int)$post['fid'];
    } elseif (is_array($thread) && !empty($thread['fid'])) {
        $fidI = (int)$thread['fid'];
    }

    $tidI = (int)($tid ?? 0);
    if ($fidI <= 0 || $tidI <= 0) {
        return;
    }

    $fields = af_atf_get_fields_for_forum($fidI);
    if (empty($fields)) {
        return;
    }

    $values = af_atf_collect_posted_values($fields);
    $GLOBALS['af_atf_context_fid'] = $fidI;
    $GLOBALS['af_atf_context_fields'] = $fields;
    $GLOBALS['af_atf_context_values'] = $values;

    $activeMechanic = af_atf_character_active_mechanic();
    foreach ($fields as $f) {
        $fieldid = (int)$f['fieldid'];
        if ($fieldid <= 0) {
            continue;
        }

        $val = isset($values[$fieldid]) ? (string)$values[$fieldid] : '';
        $val = trim($val);

        $maxlen = (int)$f['maxlen'];
        if ($maxlen > 0 && my_strlen($val) > $maxlen) {
            $val = my_substr($val, 0, $maxlen);
        }

        $type = (string)$f['type'];
        $fieldName = (string)($f['name'] ?? '');
        if ($activeMechanic === 'dnd' && ($fieldName === 'character_element' || $fieldName === 'character_faction')) {
            $values[$fieldid] = '';
            continue;
        }

        if ($type === 'kb_race' || $type === 'kb_class' || $type === 'kb_theme') {
            if (!preg_match('/^[a-z0-9_-]{2,64}$/i', $val)) {
                $val = '';
            } elseif ($val !== '') {
                $kbTypeMap = ['kb_race' => 'race', 'kb_class' => 'class', 'kb_theme' => 'theme'];
                $kbType = $kbTypeMap[$type] ?? '';
                if ($kbType === '') {
                    $val = '';
                    $values[$fieldid] = $val;
                    continue;
                }
                $list = af_atf_kb_get_list_by_type($kbType);
                $known = [];
                foreach ($list as $item) {
                    $known[(string)($item['key'] ?? '')] = true;
                }
                $opts = af_atf_parse_options((string)$f['options']);
                if (empty($known[$val]) && !array_key_exists($val, $opts)) {
                    $val = '';
                }
            }
        } elseif ($type === 'sf_attributes_pointbuy') {
            $settings = af_atf_sf_pointbuy_get_settings();
            $errorKey = null;
            $normalized = af_atf_sf_pointbuy_normalize($val, $settings, $errorKey);
            $val = !empty($normalized['ok']) ? (string)$normalized['json'] : '';
        } elseif ($type === 'character_stats') {
            $val = af_atf_normalize_character_stats_json($val);
        } elseif ($type === 'character_abilities') {
            $val = af_atf_normalize_character_abilities_json($val);
        } elseif ($type === 'kb_dynamic' || $type === 'kb_mechanic') {
            if (!preg_match('/^[a-z0-9_-]{2,64}$/i', $val)) {
                $val = '';
            }
        }

        if (($type === 'select' || $type === 'radio') && $val !== '') {
            $opts = af_atf_parse_options((string)$f['options']);
            if (!array_key_exists($val, $opts)) {
                $val = '';
            }
        }

        $values[$fieldid] = $val;
    }

    af_atf_save_values($tidI, $values);
}


function af_atf_normalize_regex(string $rx): string
{
    $rx = trim($rx);
    if ($rx === '') {
        return '';
    }

    // Если похоже на уже-делимитированный регэксп: /.../u или #...#i и т.п.
    // Берём первый символ как delimiter и ищем последний такой же delimiter с флагами.
    $del = $rx[0];
    if (!ctype_alnum($del) && $del !== '\\' && strlen($rx) >= 3) {
        // найдём закрывающий delimiter с конца (игнорируя флаги)
        $last = strrpos($rx, $del);
        if ($last !== false && $last > 0) {
            // если после последнего delimiter идут только флаги
            $flags = substr($rx, $last + 1);
            if ($flags === '' || preg_match('~^[a-zA-Z]*$~', $flags)) {
                return $rx;
            }
        }
    }

    // Иначе — считаем, что это “сырой” паттерн без delimiters.
    // Выберем delimiter, которого нет внутри паттерна.
    foreach (['~', '#', '!', '%', '@'] as $d) {
        if (strpos($rx, $d) === false) {
            return $d.$rx.$d.'u';
        }
    }

    // На самый край: экранируем тильды и всё равно оборачиваем
    $rx = str_replace('~', '\~', $rx);
    return '~'.$rx.'~u';
}

function af_atf_dh_validate(&$ph): void
{
    global $mybb, $fid, $pid, $post;

    $isEdit = !empty($ph->method) && $ph->method === 'update';

    // Не вмешиваемся в ответы (newreply) — ATF только для первого поста темы
    if (!$isEdit && !empty($ph->data['tid'])) {
        return;
    }

    $forumId = 0;
    if (!empty($ph->data['fid'])) {
        $forumId = (int)$ph->data['fid'];
    } elseif (!empty($fid)) {
        $forumId = (int)$fid;
    }

    // При редактировании — только если редактируем ПЕРВЫЙ пост темы
    if ($isEdit) {
        $pidCandidate = 0;
        if (!empty($ph->data['pid'])) {
            $pidCandidate = (int)$ph->data['pid'];
        } elseif (!empty($pid)) {
            $pidCandidate = (int)$pid;
        }

        if (!af_atf_is_first_post_context(is_array($post) ? $post : [], $pidCandidate)) {
            return;
        }
    }

    $fields = af_atf_get_fields_for_forum($forumId);
    if (empty($fields)) {
        return;
    }

    // --- 1) СТРАХОВКА MESSAGE (РОВНО "ㅤㅤ⠀") ---
    $inv = "\u{3164}\u{3164}\u{2800}";

    $curMessage = '';
    if (isset($ph->data['message']) && is_string($ph->data['message'])) {
        $curMessage = $ph->data['message'];
    } else {
        $curMessage = $mybb->get_input('message');
        $curMessage = is_string($curMessage) ? $curMessage : '';
    }

    $check = str_replace(["\u{200B}", "\u{3164}", "\u{2800}"], '', $curMessage);
    if (trim($check) === '') {
        $ph->data['message'] = $inv;
        $mybb->input['message'] = $inv;
        $_POST['message'] = $inv;
    }

    // --- 2) ВАЛИДАЦИЯ/СБОР ATF ПОЛЕЙ ---
    $incoming = $mybb->get_input('af_tf', MyBB::INPUT_ARRAY);
    if (!is_array($incoming)) {
        $incoming = [];
    }

    $clean = [];
    $GLOBALS['af_atf_context_fid'] = $forumId;
    $GLOBALS['af_atf_context_fields'] = $fields;
    $GLOBALS['af_atf_context_values'] = is_array($incoming) ? $incoming : [];
    $activeMechanic = af_atf_character_active_mechanic();
    foreach ($fields as $f) {
        $fieldid = (int)$f['fieldid'];
        $raw = $incoming[$fieldid] ?? '';
        $fieldName = (string)($f['name'] ?? '');

        // checkbox special
        if ((string)$f['type'] === 'checkbox') {
            $raw = ($raw === '1' || $raw === 1) ? '1' : '';
        }

        $type = (string)$f['type'];
        if ($activeMechanic === 'dnd' && ($fieldName === 'character_element' || $fieldName === 'character_faction')) {
            $clean[$fieldid] = '';
            continue;
        }

        // usernames: нормализуем к "uid,uid,uid" (уникальные, >0)
        if ($type === 'usernames') {
            $s = is_string($raw) ? trim($raw) : '';
            if ($s === '') {
                $val = '';
            } else {
                $uids = array_filter(array_map('intval', preg_split('~\s*,\s*~', $s)));
                $uids = array_values(array_unique(array_filter($uids, static fn($x) => $x > 0)));

                // max=... из options
                $opts = af_atf_parse_options((string)$f['options']);
                $maxUsers = 0;
                foreach (['max', 'limit', 'count'] as $k) {
                    if (isset($opts[$k])) {
                        $maxUsers = (int)$opts[$k];
                        if ($maxUsers < 0) $maxUsers = 0;
                        break;
                    }
                }
                if ($maxUsers > 0 && count($uids) > $maxUsers) {
                    $uids = array_slice($uids, 0, $maxUsers);
                }

                $val = implode(',', $uids);
            }

            if ((int)$f['required'] === 1 && $val === '') {
                $ph->set_error('missing_required_field_'.$fieldid);
                continue;
            }

            $clean[$fieldid] = $val;
            continue;
        }

        if ($type === 'kb_race' || $type === 'kb_class' || $type === 'kb_theme') {
            $val = is_string($raw) ? trim($raw) : '';
            if ((int)$f['required'] === 1 && $val === '') {
                $ph->set_error('missing_required_field_'.$fieldid);
                continue;
            }

            if ($val !== '') {
                if (!preg_match('/^[a-z0-9_-]{2,64}$/i', $val)) {
                    $ph->set_error('invalid_field_'.$fieldid);
                    continue;
                }

                $kbTypeMap = ['kb_race' => 'race', 'kb_class' => 'class', 'kb_theme' => 'theme'];
                $kbType = $kbTypeMap[$type] ?? '';
                if ($kbType === '') {
                    $ph->set_error('invalid_field_'.$fieldid);
                    continue;
                }
                $list = af_atf_kb_get_list_by_type($kbType);
                $known = [];
                foreach ($list as $item) {
                    $known[(string)($item['key'] ?? '')] = true;
                }

                $opts = af_atf_parse_options((string)$f['options']);
                if (empty($known[$val]) && !array_key_exists($val, $opts)) {
                    $ph->set_error('invalid_field_'.$fieldid);
                    continue;
                }
            }

            $clean[$fieldid] = $val;
            continue;
        }

        if ($type === 'sf_attributes_pointbuy') {
            $rawVal = is_string($raw) ? $raw : '';
            if ((int)$f['required'] === 1 && trim($rawVal) === '') {
                $ph->set_error('missing_required_field_'.$fieldid);
                continue;
            }

            if (trim($rawVal) !== '') {
                $settings = af_atf_sf_pointbuy_get_settings();
                $errorKey = null;
                $normalized = af_atf_sf_pointbuy_normalize($rawVal, $settings, $errorKey);
                if (empty($normalized['ok'])) {
                    $ph->set_error($errorKey ?: 'af_atf_sf_err_out_of_range');
                    continue;
                }
                $clean[$fieldid] = (string)$normalized['json'];
            } else {
                $clean[$fieldid] = '';
            }
            continue;
        }

        if ($type === 'character_abilities') {
            $val = af_atf_normalize_character_abilities_json(is_string($raw) ? $raw : '');
            if ((int)$f['required'] === 1 && $val === '[]') {
                $ph->set_error('missing_required_field_'.$fieldid);
                continue;
            }
            $clean[$fieldid] = $val;
            continue;
        }

        if ($type === 'character_stats') {
            $val = af_atf_normalize_character_stats_json(is_string($raw) ? $raw : '');
            if ((int)$f['required'] === 1 && ($val === '' || $val === '{}')) {
                $ph->set_error('missing_required_field_'.$fieldid);
                continue;
            }
            $clean[$fieldid] = $val;
            continue;
        }

        if ($type === 'kb_dynamic' || $type === 'kb_mechanic') {
            $val = is_string($raw) ? trim($raw) : '';
            if ((int)$f['required'] === 1 && $val === '') {
                $ph->set_error('missing_required_field_'.$fieldid);
                continue;
            }
            if ($val !== '' && !preg_match('/^[a-z0-9_-]{2,64}$/i', $val)) {
                $ph->set_error('invalid_field_'.$fieldid);
                continue;
            }
            $clean[$fieldid] = $val;
            continue;
        }

        // остальные типы
        $val = is_string($raw) ? trim($raw) : '';
        $maxlen = (int)$f['maxlen'];
        if ($maxlen > 0 && my_strlen($val) > $maxlen) {
            $val = my_substr($val, 0, $maxlen);
        }

        if ((int)$f['required'] === 1 && $val === '') {
            $ph->set_error('missing_required_field_'.$fieldid);
            continue;
        }

        $rx = trim((string)$f['regex']);
        if ($rx !== '' && $val !== '') {
            $rx2 = af_atf_normalize_regex($rx);

            if (@preg_match($rx2, '') === false) {
                $ph->set_error('invalid_field_'.$fieldid);
                continue;
            }

            if (@preg_match($rx2, $val) !== 1) {
                $ph->set_error('invalid_field_'.$fieldid);
                continue;
            }
        }

        if (($type === 'select' || $type === 'radio') && $val !== '') {
            $opts = af_atf_parse_options((string)$f['options']);
            if (!array_key_exists($val, $opts)) {
                $ph->set_error('invalid_field_'.$fieldid);
                continue;
            }
        }

        $clean[$fieldid] = $val;
    }

    $ph->data['af_atf_values'] = $clean;
}

function af_atf_dh_insert_thread(&$ph): void
{
    if (empty($ph->data['af_atf_values']) || !is_array($ph->data['af_atf_values'])) {
        return;
    }

    $tid = 0;

    if (!empty($ph->data['tid'])) {
        $tid = (int)$ph->data['tid'];
    } elseif (property_exists($ph, 'tid') && !empty($ph->tid)) {
        $tid = (int)$ph->tid;
    } elseif (method_exists($ph, 'get_thread_id')) {
        // на всякий случай, если когда-то добавишь такой метод
        $tid = (int)$ph->get_thread_id();
    }

    if ($tid <= 0) {
        return;
    }

    af_atf_save_values($tid, $ph->data['af_atf_values']);
}

function af_atf_dh_update_post(&$ph): void
{
    if (empty($ph->data['tid']) || empty($ph->data['af_atf_values']) || !is_array($ph->data['af_atf_values'])) {
        return;
    }

    global $post, $pid;

    $pidCandidate = 0;
    if (!empty($ph->data['pid'])) {
        $pidCandidate = (int)$ph->data['pid'];
    } elseif (!empty($pid)) {
        $pidCandidate = (int)$pid;
    }

    // только если редактируется первый пост
    if (!af_atf_is_first_post_context(is_array($post) ? $post : [], $pidCandidate)) {
        return;
    }

    $tid = (int)$ph->data['tid'];
    af_atf_save_values($tid, $ph->data['af_atf_values']);
}


/* -------------------- DISPLAY: showthread/forumdisplay -------------------- */

function af_atf_showthread_start(): void
{
    // Раньше мы готовили блок и вставляли его под заголовок темы.
    // Теперь поля должны быть ВНУТРИ первого поста, поэтому гасим этот вывод.
    $GLOBALS['af_atf_showthread_block'] = '';
}


function af_atf_forumdisplay_get_threads(&$query): void
{
    global $mybb, $fid, $db;

    $filters = $mybb->get_input('atf', MyBB::INPUT_ARRAY);
    if (!is_array($filters) || empty($filters)) {
        return;
    }

    $fid = (int)$fid;
    $fields = af_atf_get_fields_for_forum($fid);
    if (empty($fields)) {
        return;
    }

    $allowedFieldIds = [];
    foreach ($fields as $f) {
        $allowedFieldIds[(int)$f['fieldid']] = true;
    }

    $conds = [];
    foreach ($filters as $fieldid => $val) {
        $fieldid = (int)$fieldid;
        if ($fieldid <= 0 || empty($allowedFieldIds[$fieldid])) {
            continue;
        }

        $val = is_string($val) ? trim($val) : '';
        if ($val === '') {
            continue;
        }

        // Экранируем для LIKE: %, _ и backslash
        $like = str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $val);
        $like = $db->escape_string($like);

        $conds[] = "EXISTS(
            SELECT 1
            FROM ".TABLE_PREFIX.AF_ATF_TABLE_VALUES." v
            WHERE v.tid=t.tid
              AND v.fieldid={$fieldid}
              AND v.value LIKE '%{$like}%' ESCAPE '\\\\'
        )";
    }

    if (empty($conds)) {
        return;
    }

    if (stripos($query, ' where ') !== false) {
        $query .= " AND (".implode(' AND ', $conds).")";
    } else {
        $query .= " WHERE (".implode(' AND ', $conds).")";
    }
}

function af_atf_forumdisplay_start(): void
{
    $forumId = af_atf_resolve_current_forum_id();

    if ($forumId <= 0) {
        $GLOBALS['af_atf_forum_catalog_cta_html'] = '';
        return;
    }

    $GLOBALS['af_atf_forum_catalog_cta_html'] = af_atf_build_catalog_cta_html($forumId);
}

function af_atf_forumdisplay_thread(): void
{
    global $thread, $fid, $templates;

    $fid = (int)$fid;
    $tid = (int)($thread['tid'] ?? 0);
    if ($tid <= 0) {
        $thread['af_atf_forum_chips'] = '';
        return;
    }

    $fields = af_atf_get_fields_for_forum($fid);
    if (empty($fields)) {
        $thread['af_atf_forum_chips'] = '';
        return;
    }

    $values = af_atf_get_values_by_tid($tid);

    $chips = '';
    foreach ($fields as $f) {
        if ((int)($f['show_forum'] ?? 0) !== 1) {
            continue;
        }

        $fieldid = (int)($f['fieldid'] ?? 0);
        if ($fieldid <= 0) {
            continue;
        }

        $val = $values[$fieldid] ?? '';
        if (!is_string($val) || trim($val) === '') {
            continue;
        }

        $label = htmlspecialchars_uni((string)$f['title']);
        $valueHtml = af_atf_format_value_for_display($f, (string)$val);
        if ($valueHtml === '') {
            continue;
        }

        $chip = '';
        eval("\$chip = \"".$templates->get('af_atf_forum_chip')."\";");
        $chips .= $chip;
    }

    if ($chips === '') {
        $thread['af_atf_forum_chips'] = '';
        return;
    }

    // Только переменная (для корректной вставки через шаблон),
    // НИКАКИХ доп. "хаков" в multipage — именно они могут улетать в шапку при кривом HTML.
    $thread['af_atf_forum_chips'] =
        "\n" . AF_ATF_TPL_MARK_CHIPS . "\n"
        . '<span class="af-atf-chips">'.$chips.'</span>'
        . "\n";
}

/* -------------------- DISPLAY FORMAT / PARSER -------------------- */
function af_atf_format_value_for_display(array $field, string $val): string
{
    global $templates, $mybb, $db;

    $type = (string)$field['type'];
    $val  = (string)$val;

    if ($type === 'kb_race' || $type === 'kb_class' || $type === 'kb_theme') {
        $kbTypeMap = ['kb_race' => 'race', 'kb_class' => 'class', 'kb_theme' => 'theme'];
        $kbType = $kbTypeMap[$type] ?? '';
        if ($kbType === '') {
            return '';
        }
        return af_atf_kb_build_chip($kbType, $val, (string)$field['options']);
    }

    if ($type === 'kb_dynamic' || $type === 'kb_mechanic') {
        $fieldName = trim((string)($field['name'] ?? ''));
        $kbType = af_atf_character_arpg_contract_type($fieldName);
        if ($kbType === '' || af_atf_character_active_mechanic() !== 'arpg') {
            $kbType = af_atf_character_resolve_kb_type($field, $val);
        }

        if ($fieldName === 'character_element') {
            $entry = $kbType !== '' ? af_atf_kb_get_entry($kbType, $val) : [];
            $label = af_atf_kb_resolve_dynamic_label($field, $val);
            $iconUrl = trim((string)($entry['icon_url'] ?? ''));
            $iconClass = trim((string)($entry['icon_class'] ?? ''));

            $iconHtml = '';
            if ($iconUrl !== '') {
                $iconHtml = '<img class="af-atf-element-icon" src="' . htmlspecialchars_uni($iconUrl) . '" alt="' . htmlspecialchars_uni($label) . '" loading="lazy" />';
            } elseif ($iconClass !== '') {
                $iconHtml = '<i class="' . htmlspecialchars_uni($iconClass) . '" aria-hidden="true"></i>';
            }

            if ($iconHtml !== '') {
                return '<span class="af-atf-element-chip">' . $iconHtml . ($label !== '' ? ' <span class="af-atf-element-label">' . htmlspecialchars_uni($label) . '</span>' : '') . '</span>';
            }

            return htmlspecialchars_uni($label !== '' ? $label : $val);
        }

        if ($kbType !== '') {
            return af_atf_kb_build_chip($kbType, $val, (string)($field['options'] ?? ''));
        }

        $label = af_atf_kb_resolve_dynamic_label($field, $val);
        return htmlspecialchars_uni($label !== '' ? $label : $val);
    }

    if ($type === 'character_abilities') {
        $list = json_decode($val, true);
        if (!is_array($list) || empty($list)) {
            return '';
        }

        $cards = '';
        $chipMeta = [
            ['key' => 'type', 'label' => 'Тип', 'set' => 'ability_type'],
            ['key' => 'subtype', 'label' => 'Подтип', 'set' => 'ability_subtype'],
            ['key' => 'damage_type', 'label' => 'Тип урона', 'set' => 'ability_damage_type'],
            ['key' => 'targeting', 'label' => 'Цель', 'set' => 'ability_targeting'],
            ['key' => 'range', 'label' => 'Дальность', 'set' => ''],
            ['key' => 'damage_value', 'label' => 'Урон', 'set' => ''],
            ['key' => 'shield_value', 'label' => 'Щит', 'set' => ''],
            ['key' => 'heal_value', 'label' => 'Лечение', 'set' => ''],
        ];

        foreach ($list as $ability) {
            if (!is_array($ability)) {
                continue;
            }
            $name = trim((string)($ability['ability_name'] ?? ''));
            $description = trim((string)($ability['ability_description'] ?? ''));
            $iconUrl = trim((string)($ability['icon_url'] ?? ''));
            if ($name === '' && $description === '' && $iconUrl === '') {
                continue;
            }

            $chips = '';
            foreach ($chipMeta as $meta) {
                $rawValue = trim((string)($ability[$meta['key']] ?? ''));
                if ($rawValue === '') {
                    continue;
                }

                $displayValue = $meta['set'] !== ''
                    ? af_atf_character_ability_display_label($meta['set'], $rawValue)
                    : $rawValue;
                if ($displayValue === '') {
                    continue;
                }

                $chips .= '<span class="af-atf-ability-display-chip">'
                    . '<span class="af-atf-ability-display-chip-label">' . htmlspecialchars_uni($meta['label']) . ':</span> '
                    . '<span class="af-atf-ability-display-chip-value">' . htmlspecialchars_uni($displayValue) . '</span>'
                    . '</span>';
            }

            $iconHtml = '';
            if ($iconUrl !== '') {
                $iconHtml = '<span class="af-atf-ability-display-icon-wrap"><img class="af-atf-ability-display-icon" src="'
                    . htmlspecialchars_uni($iconUrl)
                    . '" alt="" loading="lazy" /></span>';
            }

            $titleHtml = $name !== ''
                ? '<div class="af-atf-ability-display-name">' . htmlspecialchars_uni($name) . '</div>'
                : '<div class="af-atf-ability-display-name af-atf-ability-display-name-muted">Способность</div>';

            $descriptionHtml = $description !== ''
                ? '<div class="af-atf-ability-display-description">' . nl2br(htmlspecialchars_uni($description)) . '</div>'
                : '';

            $chipsHtml = $chips !== ''
                ? '<div class="af-atf-ability-display-chips">' . $chips . '</div>'
                : '';

            $cards .= '<article class="af-atf-ability-display-card">'
                . '<div class="af-atf-ability-display-head">' . $iconHtml . $titleHtml . '</div>'
                . $chipsHtml
                . $descriptionHtml
                . '</article>';
        }

        if ($cards === '') {
            return '';
        }

        return '<section class="af-atf-abilities-display">'
            . '<div class="af-atf-abilities-display-title">Способности</div>'
            . '<div class="af-atf-abilities-display-list">' . $cards . '</div>'
            . '</section>';
    }

    if ($type === 'character_stats') {
        $decoded = json_decode($val, true);
        if (!is_array($decoded) || empty($decoded)) {
            return '';
        }

        $labels = af_atf_character_stats_labels();
        $percent = array_flip(af_atf_character_stats_percent_keys());
        $rows = '';
        foreach (af_atf_character_stats_field_keys() as $key) {
            if (!array_key_exists($key, $decoded) || !is_scalar($decoded[$key])) {
                continue;
            }
            $num = trim((string)$decoded[$key]);
            if ($num === '') {
                continue;
            }
            $label = htmlspecialchars_uni((string)($labels[$key] ?? $key));
            $suffix = isset($percent[$key]) ? '%' : '';
            $rows .= '<div class="af-atf-character-stats-display-item">'
                . '<span class="af-atf-character-stats-display-label">' . $label . '</span>'
                . '<span class="af-atf-character-stats-display-value">' . htmlspecialchars_uni($num) . $suffix . '</span>'
                . '</div>';
        }

        if ($rows === '') {
            return '';
        }

        return '<section class="af-atf-character-stats-display-wrap">'
            . '<div class="af-atf-character-stats-display-title">Характеристики</div>'
            . '<div class="af-atf-character-stats-display">' . $rows . '</div>'
            . '</section>';
    }

    if ($type === 'sf_attributes_pointbuy') {
        $decoded = json_decode($val, true);
        if (!is_array($decoded)) {
            return '';
        }

        $rows = '';
        foreach (af_atf_sf_pointbuy_get_attr_groups() as $group) {
            $rows .= '<div class="af-atf-pointbuy-display-group">';
            $rows .= '<div class="af-atf-pointbuy-group-title">'.htmlspecialchars_uni((string)$group['label']).'</div>';
            foreach ($group['items'] as $item) {
                $code = $item['code'];
                $label = htmlspecialchars_uni($item['label'].' ('.$code.')');
                $value = isset($decoded[$code]) ? (int)$decoded[$code] : 0;
                $rows .= '<div class="af-atf-pointbuy-display-row">'
                    . '<span class="af-atf-pointbuy-display-label">'.$label.'</span>'
                    . '<span class="af-atf-pointbuy-display-value">'.(int)$value.'</span>'
                    . '</div>';
            }
            $rows .= '</div>';
        }

        if ($rows === '') {
            return '';
        }

        return '<div class="af-atf-pointbuy-display">'.$rows.'</div>';
    }

    // usernames: val = "uid,uid,uid"
    if ($type === 'usernames') {
        $uids = array_filter(array_map('intval', preg_split('~\s*,\s*~', trim($val))));
        $uids = array_values(array_unique(array_filter($uids, static fn($x) => $x > 0)));

        if (empty($uids)) {
            return '';
        }

        // ограничим пачку (чтобы не устроить DDOS самому себе)
        if (count($uids) > 50) {
            $uids = array_slice($uids, 0, 50);
        }

        $in = implode(',', array_map('intval', $uids));

        static $userCache = []; // uid => username
        $need = [];
        foreach ($uids as $uid) {
            if (!isset($userCache[$uid])) {
                $need[] = $uid;
            }
        }

        if (!empty($need)) {
            $in2 = implode(',', array_map('intval', $need));
            $res = $db->simple_select('users', 'uid,username', "uid IN ({$in2})");
            while ($u = $db->fetch_array($res)) {
                $userCache[(int)$u['uid']] = (string)$u['username'];
            }
        }

        $out = [];
        foreach ($uids as $uid) {
            $username = $userCache[$uid] ?? ('#'.$uid);
            $u = htmlspecialchars_uni($username);
            $link = $mybb->settings['bburl'].'/member.php?action=profile&amp;uid='.(int)$uid;

            // “чип”
            $out[] = '<a class="af-atf-userchip" href="'.htmlspecialchars_uni($link).'">'.$u.'</a>';
        }

        return '<span class="af-atf-userchips-out">'.implode(' ', $out).'</span>';
    }

    $fieldName = trim((string)($field['name'] ?? ''));
    if (($fieldName === 'character_gen' || $fieldName === 'character_gender') && $val !== '') {
        if (function_exists('af_kb_get_arpg_mechanics_option_label')) {
            $label = (string)af_kb_get_arpg_mechanics_option_label('character_gender', $val, true);
            if ($label !== '') {
                return htmlspecialchars_uni($label);
            }
        }
        return htmlspecialchars_uni($val);
    }

    // 1) select/radio: сохраняем key, но показываем label
    if (($type === 'select' || $type === 'radio') && $val !== '') {
        $opts = af_atf_parse_options((string)$field['options']);
        if (isset($opts[$val])) {
            return htmlspecialchars_uni((string)$opts[$val]);
        }
        return htmlspecialchars_uni($val);
    }

    // 2) checkbox
    if ($type === 'checkbox') {
        if ($val === '1') {
            $opts = af_atf_parse_options((string)$field['options']);
            if (isset($opts['1'])) {
                return htmlspecialchars_uni((string)$opts['1']);
            }
            return htmlspecialchars_uni('Да');
        }
        return '';
    }

    // 3) image -> [img]
    if ($type === 'image') {
        $url = trim($val);
        if ($url === '') {
            return '';
        }
        $msg = '[img]'.$url.'[/img]';
        return af_atf_parse_message($msg, [
            'allow_html'    => 0,
            'allow_mycode'  => 1,
            'allow_smilies' => 0,
        ]);
    }

    // 4) url type
    if ($type === 'url') {
        $u = htmlspecialchars_uni(trim($val));
        if ($u === '') {
            return '';
        }
        return '<a href="'.$u.'" rel="nofollow ugc" target="_blank">'.$u.'</a>';
    }

    // 5) текст/textarea
    $allowHtmlField = !empty($field['allow_html']) ? 1 : 0;
    $allowMycode    = isset($field['parse_mycode']) ? (int)$field['parse_mycode'] : 1;
    $allowSmilies   = isset($field['parse_smilies']) ? (int)$field['parse_smilies'] : 1;

    return af_atf_parse_message($val, [
        'allow_html'    => $allowHtmlField,
        'allow_mycode'  => $allowMycode,
        'allow_smilies' => $allowSmilies,
    ]);
}

function af_atf_kb_resolve_dynamic_label(array $field, string $key): string
{
    $key = trim($key);
    if ($key === '') {
        return '';
    }

    $fieldName = trim((string)($field['name'] ?? ''));
    $kbType = af_atf_character_arpg_contract_type($fieldName);
    if ($kbType === '' || af_atf_character_active_mechanic() !== 'arpg') {
        $kbType = af_atf_character_resolve_kb_type($field, $key);
    }

    if ($kbType !== '') {
        $entry = af_atf_kb_get_entry($kbType, $key);
        if (!empty($entry)) {
            $title = af_atf_kb_pick_text($entry, 'title');
            if ($title !== '') {
                return $title;
            }
        }
    }

    $fallback = af_atf_kb_resolve_label((string)($field['options'] ?? ''), $key);
    return $fallback !== '' ? $fallback : $key;
}


function af_atf_parse_message(string $message, array $opts): string
{
    global $mybb;

    if (!class_exists('postParser')) {
        require_once MYBB_ROOT.'inc/class_parser.php';
    }

    static $parser = null;
    if (!$parser) {
        $parser = new postParser();
    }

    $allowHtmlSetting = !empty($mybb->settings['allowhtml']) ? 1 : 0;
    $groupCanHtml = 0;
    if (isset($mybb->usergroup) && is_array($mybb->usergroup)) {
        $groupCanHtml = !empty($mybb->usergroup['canusehtml']) ? 1 : 0;
    }

    $allowHtml = (!empty($opts['allow_html']) && $allowHtmlSetting && $groupCanHtml) ? 1 : 0;

    $options = [
        'allow_html'      => $allowHtml,
        'allow_mycode'    => !empty($opts['allow_mycode']) ? 1 : 0,
        'allow_smilies'   => !empty($opts['allow_smilies']) ? 1 : 0,
        'allow_imgcode'   => 1,
        'filter_badwords' => 1,
        'nl2br'           => 1,
    ];

    return $parser->parse_message($message, $options);
}

function af_atf_build_display_block_for_tid_fid(int $tid, int $fid): string
{
    global $templates;

    $tid = (int)$tid;
    $fid = (int)$fid;

    if ($tid <= 0 || $fid <= 0) {
        return '';
    }

    $fields = af_atf_get_fields_for_forum($fid);
    if (empty($fields)) {
        return '';
    }

    $values = af_atf_get_values_by_tid($tid);
    if (empty($values)) {
        return '';
    }

    $rows = '';
    foreach ($fields as $f) {
        if ((int)($f['show_thread'] ?? 0) !== 1) {
            continue;
        }

        $fieldid = (int)($f['fieldid'] ?? 0);
        if ($fieldid <= 0) {
            continue;
        }

        $val = $values[$fieldid] ?? '';
        $val = is_string($val) ? trim($val) : '';
        if ($val === '') {
            continue;
        }

        $nameClass = preg_replace('~[^a-z0-9_]+~i', '_', (string)$f['name']);
        $label = htmlspecialchars_uni((string)$f['title']);
        $valueHtml = af_atf_format_value_for_display($f, $val);

        $format = trim((string)$f['format']);
        if ($format === '') {
            $format = '<span class="af-atf-label">{LABEL}:</span> <span class="af-atf-value">{VALUE}</span>';
        }

        $line = str_replace(['{LABEL}', '{VALUE}'], [$label, $valueHtml], $format);
        $line = '<div class="af-atf-field af-atf-field-'.$nameClass.'" data-fieldid="'.$fieldid.'">'.$line.'</div>';

        $row = '';
        eval("\$row = \"".$templates->get('af_atf_display_row')."\";");
        $rows .= $row;
    }

    if ($rows === '') {
        return '';
    }

    $block = '';
    eval("\$block = \"".$templates->get('af_atf_display_block')."\";");

    // Обёртка именно “внутри поста”, чтобы можно было отдельно стилизовать
    return '<div class="af-atf-inpost">'.$block.'</div>';
}

function af_atf_message_is_effectively_empty(string $htmlMessage): bool
{
    $s = $htmlMessage;

    // Убираем наиболее частые “пустые” штуки после парсинга
    $s = str_ireplace(
        ['<br />', '<br>', '<br/>', '&nbsp;', '&#160;'],
        '',
        $s
    );

    // Убираем наши “невидимые” символы + zero-width
    $s = str_replace(["\u{200B}", "\u{3164}", "\u{2800}"], '', $s);

    // Убираем теги
    $s = trim(strip_tags($s));

    return ($s === '');
}

function af_atf_postbit(&$post): void
{
    // Нас интересует только showthread (в постбитах других страниц тоже бывает)
    if (!defined('THIS_SCRIPT') || THIS_SCRIPT !== 'showthread.php') {
        return;
    }

    if (!is_array($post)) {
        return;
    }

    $pid = (int)($post['pid'] ?? 0);
    if ($pid <= 0) {
        return;
    }

    // Встраиваем ТОЛЬКО в первый пост темы
    if (!af_atf_is_first_post_context($post, $pid)) {
        return;
    }

    $tid = (int)($post['tid'] ?? 0);
    $fid = (int)($post['fid'] ?? 0);

    if ($tid <= 0 || $fid <= 0) {
        return;
    }

    // Кешируем на один рендер страницы, чтобы не собирать блок заново
    static $blockCache = [];

    if (!array_key_exists($tid, $blockCache)) {
        $blockCache[$tid] = af_atf_build_display_block_for_tid_fid($tid, $fid);
    }

    $block = (string)$blockCache[$tid];
    if ($block === '') {
        return;
    }

    $cur = isset($post['message']) && is_string($post['message']) ? $post['message'] : '';

    // Если пост по сути пустой (твой placeholder) — ЗАМЕНЯЕМ сообщение на поля
    if (af_atf_message_is_effectively_empty($cur)) {
        $post['message'] = $block;
        return;
    }

    // Если вдруг в первом посте всё же есть текст — не уничтожаем его, а добавляем поля сверху
    $post['message'] = $block . "\n" . $cur;
}

/* -------------------- SETTINGS -------------------- */

function af_atf_ensure_settings(): void
{
    global $db;

    $gid = (int)$db->fetch_field(
        $db->simple_select('settinggroups', 'gid', "name='af_advancedthreadfields'", ['limit' => 1]),
        'gid'
    );

    if (!$gid) {
        $db->insert_query('settinggroups', [
            'name'        => 'af_advancedthreadfields',
            'title'       => 'AdvancedThreadFields',
            'description' => 'Настройки аддона AdvancedThreadFields.',
            'disporder'   => 1,
            'isdefault'   => 0,
        ]);
        $gid = (int)$db->insert_id();
    }

    $sid = (int)$db->fetch_field(
        $db->simple_select('settings', 'sid', "name='af_advancedthreadfields_enabled'", ['limit' => 1]),
        'sid'
    );

    if (!$sid) {
        $db->insert_query('settings', [
            'name'        => 'af_advancedthreadfields_enabled',
            'title'       => 'Включить AdvancedThreadFields',
            'description' => 'Включает обработку полей тем (ввод/сохранение/вывод).',
            'optionscode' => 'yesno',
            'value'       => '1',
            'disporder'   => 1,
            'gid'         => $gid,
        ]);
    }

    $settings = [
        'af_atf_sf_total_points' => [
            'title' => 'SF Point-buy: total points',
            'description' => 'Total points budget for attribute point-buy.',
            'optionscode' => 'numeric',
            'value' => '10',
            'disporder' => 10,
        ],
        'af_atf_sf_min_value' => [
            'title' => 'SF Point-buy: minimum value',
            'description' => 'Minimum attribute value.',
            'optionscode' => 'numeric',
            'value' => '8',
            'disporder' => 11,
        ],
        'af_atf_sf_max_value' => [
            'title' => 'SF Point-buy: maximum value',
            'description' => 'Maximum attribute value.',
            'optionscode' => 'numeric',
            'value' => '18',
            'disporder' => 12,
        ],
        'af_atf_sf_base_value' => [
            'title' => 'SF Point-buy: base value',
            'description' => 'Base attribute value before point-buy.',
            'optionscode' => 'numeric',
            'value' => '10',
            'disporder' => 13,
        ],
        'af_atf_sf_cost_curve' => [
            'title' => 'SF Point-buy: cost curve (JSON)',
            'description' => 'JSON rules for cost calculation.',
            'optionscode' => 'textarea',
            'value' => json_encode(af_atf_sf_pointbuy_default_curve(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'disporder' => 14,
        ],
        'af_atf_sf_allow_negative_remaining' => [
            'title' => 'SF Point-buy: allow negative remaining',
            'description' => 'Allow overspending the budget.',
            'optionscode' => 'yesno',
            'value' => '0',
            'disporder' => 15,
        ],
        'af_atf_sf_require_exact_spend' => [
            'title' => 'SF Point-buy: require exact spend',
            'description' => 'Require spending exactly the total points.',
            'optionscode' => 'yesno',
            'value' => '0',
            'disporder' => 16,
        ],
        'af_atf_assets_blacklist' => [
            'title' => 'ATF assets blacklist (one rule per line)',
            'description' => 'Disable ATF JS/CSS on listed pages. Format: script.php or script.php?action=name.',
            'optionscode' => 'textarea',
            'value' => "index.php
usercp.php
userlist.php
search.php
gallery.php",
            'disporder' => 17,
        ],
    ];

    foreach ($settings as $name => $row) {
        $sid = (int)$db->fetch_field(
            $db->simple_select('settings', 'sid', "name='".$db->escape_string($name)."'", ['limit' => 1]),
            'sid'
        );
        if ($sid) {
            continue;
        }
        $db->insert_query('settings', array_merge($row, [
            'name' => $name,
            'gid' => $gid,
        ]));
    }

    rebuild_settings();
}

function af_atf_remove_settings(): void
{
    global $db;

    $db->delete_query(
        'settings',
        "name IN ('af_advancedthreadfields_enabled','af_atf_sf_total_points','af_atf_sf_min_value','af_atf_sf_max_value','af_atf_sf_base_value','af_atf_sf_cost_curve','af_atf_sf_allow_negative_remaining','af_atf_sf_require_exact_spend','af_atf_assets_blacklist')"
    );
    $db->delete_query('settinggroups', "name='af_advancedthreadfields'");

    rebuild_settings();
}

/* -------------------- TEMPLATES: ADDON TEMPLATES INSTALL -------------------- */
function af_atf_install_templates(): void
{
    $path = AF_ADDONS . AF_ATF_ID . '/templates/advancedthreadfields.html';
    if (!file_exists($path)) {
        return;
    }

    $html = file_get_contents($path);
    if ($html === false || trim($html) === '') {
        return;
    }

    $chunks = preg_split('~<!--\s*TEMPLATE:\s*([a-z0-9_\-]+)\s*-->~i', $html, -1, PREG_SPLIT_DELIM_CAPTURE);
    if (!is_array($chunks) || count($chunks) < 3) {
        return;
    }

    global $db;
    if (!is_object($db)) {
        return;
    }

    // ВАЖНО: у тебя sid=-2 не работает/не виден, поэтому аддон-шаблоны кладём в Global templates (sid=-1)
    $targetSid = -1;

    $prefix = 'af_atf_';

    for ($i = 1; $i < count($chunks); $i += 2) {
        $name = trim((string)$chunks[$i]);
        $content = trim((string)($chunks[$i + 1] ?? ''));

        if ($name === '' || $content === '') {
            continue;
        }

        // если имя уже начинается с af_atf_, не префиксуем повторно
        $tplName = (stripos($name, $prefix) === 0) ? $name : ($prefix . $name);
        $tplNameEsc = $db->escape_string($tplName);

        // 1) обновляем/создаём в sid=-1
        $existsId = (int)$db->fetch_field(
            $db->simple_select('templates', 'tid', "title='{$tplNameEsc}' AND sid='{$targetSid}'", ['limit' => 1]),
            'tid'
        );

        $data = [
            'title'    => $tplName,
            'template' => $db->escape_string($content),
            'sid'      => $targetSid,
            'version'  => '1800',
            'dateline' => TIME_NOW,
        ];

        if ($existsId > 0) {
            $db->update_query('templates', $data, "tid=" . (int)$existsId);
        } else {
            $db->insert_query('templates', $data);
        }

        // 2) если вдруг где-то остались старые копии в sid=-2 — обновим их тоже (не обязательно, но чтоб не было “двух истин”)
        $legacyId = (int)$db->fetch_field(
            $db->simple_select('templates', 'tid', "title='{$tplNameEsc}' AND sid='-2'", ['limit' => 1]),
            'tid'
        );
        if ($legacyId > 0) {
            $db->update_query('templates', [
                'template' => $db->escape_string($content),
                'dateline' => TIME_NOW,
            ], "tid=" . (int)$legacyId);
        }
    }

    // Пересоберём кеши шаблонов, чтобы $templates->get() начал видеть новые шаблоны сразу
    if (function_exists('cache_templates')) {
        try {
            foreach (af_atf_tpl_get_target_sids() as $sid) {
                cache_templates((int)$sid);
            }
        } catch (Throwable $e) {
            // молча
        }
    }
}



/* -------------------- TEMPLATE AUTO-INSERT (activate/deactivate) -------------------- */
function af_atf_tpl_get_base_template_body(string $title): ?string
{
    $title = trim($title);
    if ($title === '') {
        return null;
    }

    // 1) master (-2) если он есть
    $m = af_atf_tpl_load_one(-2, $title);
    if ($m && isset($m['template']) && is_string($m['template']) && $m['template'] !== '') {
        return (string)$m['template'];
    }

    // 2) fallback: дефолтный набор (sid=1)
    $d = af_atf_tpl_load_one(1, $title);
    if ($d && isset($d['template']) && is_string($d['template']) && $d['template'] !== '') {
        return (string)$d['template'];
    }

    return null;
}

/**
 * Гарантированная вставка блока:
 * - если маркер уже есть -> ничего не делаем
 * - пробуем after-anchors, затем before-anchors
 * - затем: before </form> / before </tr> / before </tbody> / before </body>
 * - затем: просто в конец
 */
function af_atf_tpl_insert_block(
    string $tpl,
    string $marker,
    string $block,
    array $afterAnchors = [],
    array $beforeAnchors = []
): string {
    if ($marker !== '' && strpos($tpl, $marker) !== false) {
        return $tpl;
    }

    // after anchors (строковые)
    foreach ($afterAnchors as $a) {
        $a = (string)$a;
        if ($a !== '' && strpos($tpl, $a) !== false) {
            $pos = strpos($tpl, $a);
            $posEnd = $pos + strlen($a);
            return substr($tpl, 0, $posEnd) . $block . substr($tpl, $posEnd);
        }
    }

    // before anchors (строковые)
    foreach ($beforeAnchors as $a) {
        $a = (string)$a;
        if ($a !== '' && strpos($tpl, $a) !== false) {
            $pos = strpos($tpl, $a);
            return substr($tpl, 0, $pos) . $block . substr($tpl, $pos);
        }
    }

    // fallback: перед закрывающими тегами (наиболее безопасные места)
    foreach (['</form>', '</tr>', '</tbody>', '</body>'] as $closer) {
        $p = stripos($tpl, $closer);
        if ($p !== false) {
            return substr($tpl, 0, $p) . $block . substr($tpl, $p);
        }
    }

    // последний шанс — в конец
    return $tpl . $block;
}

function af_atf_tpl_force_edit_by_title(string $title, string $tpl): string
{
    $title = trim($title);

    // что вставляем: маркер + переменная
    $insertInput = "\n" . AF_ATF_TPL_MARK_INPUT . "\n" . '{$af_atf_input_html}' . "\n";
    $insertShow  = "\n" . AF_ATF_TPL_MARK_SHOW  . "\n" . '{$af_atf_showthread_block}' . "\n";
    $insertChips = "\n" . AF_ATF_TPL_MARK_CHIPS . "\n" . '{$thread[\'af_atf_forum_chips\']}' . "\n";

    // вставка после regex-якоря
    $insert_after_rx = static function(string $html, string $pattern, string $insert, string $marker): string {
        if ($marker !== '' && strpos($html, $marker) !== false) {
            return $html;
        }

        $count = 0;
        $new = @preg_replace($pattern, '$1' . $insert, $html, 1, $count);

        if ($count > 0 && is_string($new) && $new !== $html) {
            return $new;
        }

        return $html;
    };

    // мягкий fallback (для страниц-форм это нормально)
    $fallback = static function(string $html, string $insert, string $marker): string {
        if ($marker !== '' && strpos($html, $marker) !== false) {
            return $html;
        }

        foreach (['</form>', '</tbody>', '</tr>', '</table>', '</body>'] as $closer) {
            $p = stripos($html, $closer);
            if ($p !== false) {
                return substr($html, 0, $p) . $insert . substr($html, $p);
            }
        }

        return $html . $insert;
    };

    /* -------------------- newthread / editpost -------------------- */
    if ($title === 'newthread' || $title === 'editpost') {
        $rx = '~(<td\s+class=(["\'])trow2\2\s*>\s*\{\$prefixselect\}\s*<input\b[^>]*\bname=(["\'])subject\3[^>]*>\s*</td>)~is';
        $out = $insert_after_rx($tpl, $rx, $insertInput, AF_ATF_TPL_MARK_INPUT);

        if ($out === $tpl) {
            $out = $fallback($tpl, $insertInput, AF_ATF_TPL_MARK_INPUT);
        }

        return $out;
    }

    /* -------------------- showthread -------------------- */
    if ($title === 'showthread') {
        $rx = '~(<strong>\s*\{\$thread\[(?:\'|")displayprefix(?:\'|")\]\}\s*\{\$thread\[(?:\'|")subject(?:\'|")\]\}\s*</strong>)~is';
        $out = $insert_after_rx($tpl, $rx, $insertShow, AF_ATF_TPL_MARK_SHOW);

        if ($out === $tpl) {
            $out = $fallback($tpl, $insertShow, AF_ATF_TPL_MARK_SHOW);
        }

        return $out;
    }

    /* -------------------- forumdisplay_thread --------------------
     * ВАЖНО:
     * Это ШАБЛОН СТРОКИ (<tr>...</tr>) — сюда нельзя делать "fallback перед </tr>"
     * потому что легко вывалиться вне <td> и поломать таблицу (и тогда чипсы улетают в шапку рядом с кнопками).
     *
     * Поэтому: только безопасные якоря. Не нашли — НИЧЕГО НЕ ВСТАВЛЯЕМ.
     */
    if ($title === 'forumdisplay_thread') {
        // Вариант 1: после {$thread['multipage']} в subject-блоке (как было задумано)
        $rx1 =
            '~(<span>\s*\{\$prefix\}\s*(?:&nbsp;|\s)*\{\$gotounread\}\s*\{\$thread\[(?:\'|")threadprefix(?:\'|")\]\}.*?'
          . '<a\b[^>]*\bhref=\{\$thread\[(?:\'|")threadlink(?:\'|")\]\}[^>]*>\s*\{\$thread\[(?:\'|")subject(?:\'|")\]\}\s*</a>.*?'
          . '\{\$thread\[(?:\'|")multipage(?:\'|")\]\}\s*</span>)~is';

        $out = $insert_after_rx($tpl, $rx1, $insertChips, AF_ATF_TPL_MARK_CHIPS);
        if ($out !== $tpl) {
            return $out;
        }

        // Вариант 2: если структура другая — цепляемся за </a> + multipage рядом
        $rx2 =
            '~(<a\b[^>]*\bhref=\{\$thread\[(?:\'|")threadlink(?:\'|")\]\}[^>]*>\s*\{\$thread\[(?:\'|")subject(?:\'|")\]\}\s*</a>.*?\{\$thread\[(?:\'|")multipage(?:\'|")\]\})~is';

        $out = $insert_after_rx($tpl, $rx2, $insertChips, AF_ATF_TPL_MARK_CHIPS);
        if ($out !== $tpl) {
            return $out;
        }

        // Вариант 3: самый общий и безопасный — сразу после ссылки на тему (внутри TD)
        $rx3 =
            '~(<a\b[^>]*\bhref=\{\$thread\[(?:\'|")threadlink(?:\'|")\]\}[^>]*>\s*\{\$thread\[(?:\'|")subject(?:\'|")\]\}\s*</a>)~is';

        $out = $insert_after_rx($tpl, $rx3, $insertChips, AF_ATF_TPL_MARK_CHIPS);
        if ($out !== $tpl) {
            return $out;
        }

        // Якоря не нашли — не трогаем шаблон вообще.
        return $tpl;
    }

    return $tpl;
}

function af_atf_apply_template_edits(): void
{
    global $db, $cache;

    if (!is_object($db)) {
        return;
    }

    af_atf_tpl_ensure_admin_template_funcs();

    // Сначала чистим наши следы (без фанатизма), чтобы не было дублей
    af_atf_revert_template_edits();

    $targets = af_atf_tpl_get_target_sids();
    $titles  = ['newthread', 'editpost', 'showthread', 'forumdisplay_thread'];

    foreach ($targets as $sid) {
        $sid = (int)$sid;

        foreach ($titles as $title) {
            $title = (string)$title;

            // 1) если запись есть — редактируем её
            $row = af_atf_tpl_load_one($sid, $title);
            if ($row) {
                $old = (string)$row['template'];
                $new = af_atf_tpl_force_edit_by_title($title, $old);

                if ($new !== $old) {
                    af_atf_tpl_update_all_rows($sid, $title, $new);
                }
                continue;
            }

            // 2) если записи нет — создаём override из базы
            $base = af_atf_tpl_get_base_template_body($title);
            if (!is_string($base) || trim($base) === '') {
                continue;
            }

            $new = af_atf_tpl_force_edit_by_title($title, $base);

            // если по какой-то причине ничего не вставилось — не создаём мусор
            if ($new === $base) {
                continue;
            }

            af_atf_tpl_insert_override($sid, $title, $new);
        }
    }

    // пересборка кеша шаблонов (чтобы правки реально применились без плясок)
    af_atf_tpl_rebuild_all_caches();

    if (is_object($cache)) {
        $cache->update('af_atf_tpl_state', ['applied' => 1, 'time' => TIME_NOW]);
    }
}

function af_atf_revert_template_edits(): void
{
    global $db;

    if (!is_object($db)) {
        return;
    }

    af_atf_tpl_ensure_admin_template_funcs();

    $targets = af_atf_tpl_get_target_sids();
    $titles  = ['newthread', 'editpost', 'showthread', 'forumdisplay_thread'];

    foreach ($targets as $sid) {
        $sid = (int)$sid;

        foreach ($titles as $title) {
            $titleEsc = $db->escape_string((string)$title);

            $q = $db->simple_select('templates', 'tid,template', "title='{$titleEsc}' AND sid={$sid}");
            while ($row = $db->fetch_array($q)) {
                $tid = (int)$row['tid'];
                $tpl = (string)$row['template'];
                $old = $tpl;

                // INPUT
                $tpl = preg_replace(
                    '~\s*' . preg_quote(AF_ATF_TPL_MARK_INPUT, '~') . '\s*\{\$af_atf_input_html\}\s*~i',
                    '',
                    $tpl
                );

                // SHOW
                $tpl = preg_replace(
                    '~\s*' . preg_quote(AF_ATF_TPL_MARK_SHOW, '~') . '\s*\{\$af_atf_showthread_block\}\s*~i',
                    '',
                    $tpl
                );

                // CHIPS (разные кавычки)
                $tpl = preg_replace(
                    '~\s*' . preg_quote(AF_ATF_TPL_MARK_CHIPS, '~') . '\s*\{\$thread\[(?:\'|")af_atf_forum_chips(?:\'|")\]\}\s*~i',
                    '',
                    $tpl
                );

                // если вдруг остались голые маркеры
                $tpl = preg_replace('~\s*' . preg_quote(AF_ATF_TPL_MARK_INPUT, '~') . '\s*~i', '', $tpl);
                $tpl = preg_replace('~\s*' . preg_quote(AF_ATF_TPL_MARK_SHOW,  '~') . '\s*~i', '', $tpl);
                $tpl = preg_replace('~\s*' . preg_quote(AF_ATF_TPL_MARK_CHIPS, '~') . '\s*~i', '', $tpl);

                if (is_string($tpl) && $tpl !== $old) {
                    $db->update_query('templates', [
                        'template' => $db->escape_string($tpl),
                        'dateline' => TIME_NOW,
                    ], "tid={$tid}");
                }
            }
        }
    }

    af_atf_tpl_rebuild_all_caches();
}

/**
 * Гарантирует доступность find_replace_templatesets().
 * В ACP она обычно уже есть, но AF-роутер/контроллеры могут её не подключать.
 */
function af_atf_tpl_ensure_admin_template_funcs(): void
{
    if (function_exists('find_replace_templatesets')) {
        return;
    }

    if (!defined('MYBB_ROOT')) {
        return;
    }

    $path = MYBB_ROOT . 'inc/adminfunctions_templates.php';
    if (file_exists($path)) {
        require_once $path;
    }
}

/**
 * Унифицированный “find/replace” по templatesets.
 * 1) Если есть find_replace_templatesets — используем его (это нужно, чтобы кеш шаблонов обновился).
 * 2) Фолбэк: прямое обновление БД (на крайний случай).
 */

 function af_atf_tpl_load_one(int $sid, string $title): ?array
{
    global $db;

    if (!is_object($db)) {
        return null;
    }

    $titleEsc = $db->escape_string($title);
    $q = $db->simple_select('templates', 'tid,template', "title='{$titleEsc}' AND sid=".(int)$sid, ['limit' => 1]);
    $row = $db->fetch_array($q);

    if (!is_array($row) || empty($row)) {
        return null;
    }

    return [
        'tid' => (int)$row['tid'],
        'template' => (string)$row['template'],
    ];
}

function af_atf_tpl_update_all_rows(int $sid, string $title, string $newTemplate): int
{
    global $db;

    if (!is_object($db)) {
        return 0;
    }

    $titleEsc = $db->escape_string($title);
    $q = $db->simple_select('templates', 'tid,template', "title='{$titleEsc}' AND sid=".(int)$sid);

    $updated = 0;
    while ($row = $db->fetch_array($q)) {
        $tid = (int)$row['tid'];
        $old = (string)$row['template'];

        if ($old === $newTemplate) {
            continue;
        }

        $db->update_query('templates', [
            'template' => $db->escape_string($newTemplate),
            'dateline' => TIME_NOW,
        ], "tid={$tid}");

        $updated++;
    }

    return $updated;
}

function af_atf_tpl_insert_override(int $sid, string $title, string $template): void
{
    global $db;

    if (!is_object($db)) {
        return;
    }

    $db->insert_query('templates', [
        'title'    => $title,
        'template' => $db->escape_string($template),
        'sid'      => (int)$sid,
        'version'  => '1800',
        'dateline' => TIME_NOW,
    ]);
}

function af_atf_tpl_apply_to_sid(int $sid, string $title, callable $editor): void
{
    // 1) если запись в этом sid существует — редактируем её
    $row = af_atf_tpl_load_one($sid, $title);
    if ($row) {
        $old = (string)$row['template'];
        $new = (string)$editor($old);

        if ($new !== $old) {
            af_atf_tpl_update_all_rows($sid, $title, $new);
        }
        return;
    }

    // 2) если записи нет — создаём override из master (-2), но только если editor реально что-то меняет
    if ($sid === -2) {
        return;
    }

    $master = af_atf_tpl_load_one(-2, $title);
    if (!$master) {
        return;
    }

    $old = (string)$master['template'];
    $new = (string)$editor($old);

    if ($new !== $old) {
        af_atf_tpl_insert_override($sid, $title, $new);
    }
}

/**
 * Вставка ПОСЛЕ needle (строковый метод, без магии).
 * - не вставляет, если маркер уже есть
 * - возвращает исходник, если needle не найден
 */
function af_atf_tpl_insert_after(string $tpl, string $needle, string $insert, string $marker): string
{
    if ($marker !== '' && strpos($tpl, $marker) !== false) {
        return $tpl;
    }

    $pos = strpos($tpl, $needle);
    if ($pos === false) {
        return $tpl;
    }

    $posEnd = $pos + strlen($needle);
    return substr($tpl, 0, $posEnd) . $insert . substr($tpl, $posEnd);
}

/**
 * Вставка ПЕРЕД needle (строковый метод).
 */
function af_atf_tpl_insert_before(string $tpl, string $needle, string $insert, string $marker): string
{
    if ($marker !== '' && strpos($tpl, $marker) !== false) {
        return $tpl;
    }

    $pos = strpos($tpl, $needle);
    if ($pos === false) {
        return $tpl;
    }

    return substr($tpl, 0, $pos) . $insert . substr($tpl, $pos);
}

function af_atf_tpl_get_target_sids(): array
{
    global $db;

    // sid=-1 (Global templates) + все templatesets sid>0 (включая sid=1)
    $sids = [-1];

    if (!is_object($db)) {
        return $sids;
    }

    $q = $db->simple_select('templatesets', 'sid', '', ['order_by' => 'sid', 'order_dir' => 'ASC']);
    while ($row = $db->fetch_array($q)) {
        $sid = (int)($row['sid'] ?? 0);
        if ($sid > 0) {
            $sids[] = $sid;
        }
    }

    $sids = array_values(array_unique($sids));
    sort($sids);
    return $sids;
}


function af_atf_tpl_find_replace(string $title, string $pattern, string $replacement, int $sid): void
{
    global $db;

    if (!is_object($db)) {
        return;
    }

    $title = trim($title);
    if ($title === '') {
        return;
    }

    // Если replacement содержит один из наших маркеров — будем избегать дублей на fallback-вставках
    $marker = '';
    foreach ([AF_ATF_TPL_MARK_INPUT, AF_ATF_TPL_MARK_SHOW, AF_ATF_TPL_MARK_CHIPS] as $m) {
        if (strpos($replacement, $m) !== false) {
            $marker = $m;
            break;
        }
    }

    $titleEsc = $db->escape_string($title);

    // 1) Забираем ВСЕ шаблоны с таким title+sid (на случай дублей)
    $q = $db->simple_select('templates', 'tid,template', "title='{$titleEsc}' AND sid=".(int)$sid);
    $foundAny = false;

    while ($row = $db->fetch_array($q)) {
        $foundAny = true;

        $tid  = (int)$row['tid'];
        $body = (string)$row['template'];

        // Если маркер уже есть — ничего не делаем (это гасит повторные fallback-вставки)
        if ($marker !== '' && strpos($body, $marker) !== false) {
            continue;
        }

        $count = 0;
        $new = @preg_replace($pattern, $replacement, $body, 1, $count);

        if ($count > 0 && is_string($new) && $new !== $body) {
            $db->update_query('templates', [
                'template' => $db->escape_string($new),
                'dateline' => TIME_NOW,
            ], "tid={$tid}");
        }
    }

    // 2) Если шаблона в этом SID нет — создаём оверрайд из Master (-2) и пытаемся применить замену
    if ($foundAny) {
        return;
    }

    // Нельзя “создать master из master”
    if ((int)$sid === -2) {
        return;
    }

    $qm = $db->simple_select('templates', 'template', "title='{$titleEsc}' AND sid=-2", ['limit' => 1]);
    $masterBody = $db->fetch_field($qm, 'template');

    if (!is_string($masterBody) || $masterBody === '') {
        return;
    }

    // Если маркер уже есть в мастере — нет смысла плодить оверрайд
    if ($marker !== '' && strpos($masterBody, $marker) !== false) {
        return;
    }

    $count = 0;
    $new = @preg_replace($pattern, $replacement, $masterBody, 1, $count);

    // Если якорь не найден — не создаём пустой оверрайд
    if (!($count > 0 && is_string($new) && $new !== $masterBody)) {
        return;
    }

    $db->insert_query('templates', [
        'title'    => $title,
        'template' => $db->escape_string($new),
        'sid'      => (int)$sid,
        'version'  => '1800',
        'dateline' => TIME_NOW,
    ]);
}

function af_atf_tpl_fr(string $title, string $pattern, string $replacement): void
{
    foreach (af_atf_tpl_get_target_sids() as $sid) {
        af_atf_tpl_find_replace($title, $pattern, $replacement, (int)$sid);
    }
}



/* --------------------------------------------------------------------
 * Ниже функции оставлены для совместимости, если где-то в коде они ещё вызываются.
 * Теперь они просто “прокидывают” логику на новый механизм.
 * -------------------------------------------------------------------- */

function af_atf_template_insert_var(string $title, string $mark, string $var, array $rules): void
{
    // Совместимость: сначала удалим, потом вставим “по правилам”.
    // В текущей версии ATF мы используем строго определённые точки (см. apply_template_edits),
    // но если этот хелпер вызовется — сделаем максимально мягко.
    af_atf_tpl_ensure_admin_template_funcs();

    $rm = '#\s*' . preg_quote($mark, '#') . '\s*' . preg_quote($var, '#') . '\s*#i';
    af_atf_tpl_fr($title, $rm, '');

    $insert = "\n{$mark}\n{$var}\n";

    // after anchors
    if (!empty($rules['after']) && is_array($rules['after'])) {
        foreach ($rules['after'] as $anchor) {
            $anchor = (string)$anchor;
            if ($anchor !== '') {
                $rx = '#(' . preg_quote($anchor, '#') . ')#';
                af_atf_tpl_fr($title, $rx, '$1' . $insert);
                return;
            }
        }
    }

    // before anchors
    if (!empty($rules['before']) && is_array($rules['before'])) {
        foreach ($rules['before'] as $anchor) {
            $anchor = (string)$anchor;
            if ($anchor !== '') {
                $rx = '#(' . preg_quote($anchor, '#') . ')#';
                af_atf_tpl_fr($title, $rx, $insert . '$1');
                return;
            }
        }
    }

    // fallback after <form>
    if (!empty($rules['fallback_after_form'])) {
        af_atf_tpl_fr($title, '#(<form\b[^>]*>)#i', '$1' . $insert);
        return;
    }

    // last resort append — без доступа к “append” у find_replace_templatesets это не идеально,
    // но хотя бы попытка: вставим перед </form> если есть, иначе перед </body>, иначе — ничего.
    af_atf_tpl_fr($title, '#(</form>)#i', $insert . '$1');
    af_atf_tpl_fr($title, '#(</body>)#i', $insert . '$1');
}

function af_atf_template_remove_mark(string $title, string $mark, string $var): void
{
    af_atf_tpl_ensure_admin_template_funcs();

    $rm = '#\s*' . preg_quote($mark, '#') . '\s*' . preg_quote($var, '#') . '\s*#i';
    af_atf_tpl_fr($title, $rm, '');
}

function af_atf_template_insert_after_subject_row(string $title, string $mark, string $var): void
{
    af_atf_tpl_ensure_admin_template_funcs();

    // Сначала уберём возможные старые вставки
    $rm = '#\s*' . preg_quote($mark, '#') . '\s*' . preg_quote($var, '#') . '\s*#i';
    af_atf_tpl_fr($title, $rm, '');

    $insert = "\n{$mark}\n{$var}\n";
    $rxSubjectRow = '#(<tr\b[^>]*>.*?name=(["\'])subject\2.*?</tr>)#is';

    af_atf_tpl_fr($title, $rxSubjectRow, '$1' . $insert);
}

function af_atf_templates_have_marks(): bool
{
    global $db;

    if (!is_object($db)) {
        return false;
    }

    $targets = af_atf_tpl_get_target_sids();
    $titles  = ['newthread', 'editpost', 'showthread', 'forumdisplay_thread'];

    $need = [
        'newthread' => [AF_ATF_TPL_MARK_INPUT, '{$af_atf_input_html}'],
        'editpost'  => [AF_ATF_TPL_MARK_INPUT, '{$af_atf_input_html}'],
        'showthread'=> [AF_ATF_TPL_MARK_SHOW,  '{$af_atf_showthread_block}'],
        'forumdisplay_thread' => [AF_ATF_TPL_MARK_CHIPS, 'af_atf_forum_chips'],
    ];

    $tplMap = [];
    $titleIn = "'" . implode("','", array_map([$db, 'escape_string'], $titles)) . "'";
    $sidIn   = implode(',', array_map('intval', $targets));

    $q = $db->simple_select(
        'templates',
        'sid,title,template',
        "title IN ({$titleIn}) AND sid IN ({$sidIn})"
    );

    while ($row = $db->fetch_array($q)) {
        $sid = (int)$row['sid'];
        $title = (string)$row['title'];
        $tplMap[$sid][$title] = (string)$row['template'];
    }

    foreach ($targets as $sid) {
        foreach ($need as $title => $parts) {
            if (empty($tplMap[$sid][$title])) {
                return false; // нет записи => точно не вставлено (и ты это видишь в ACP)
            }

            $tpl = (string)$tplMap[$sid][$title];

            if (strpos($tpl, (string)$parts[0]) === false) {
                return false;
            }

            if ($title === 'forumdisplay_thread') {
                if (stripos($tpl, (string)$parts[1]) === false) {
                    return false;
                }
            } else {
                if (strpos($tpl, (string)$parts[1]) === false) {
                    return false;
                }
            }
        }
    }

    return true;
}

function af_atf_template_edits_ensure_applied(): void
{
    global $cache;

    $state = is_object($cache) ? $cache->read('af_atf_tpl_state') : null;

    // если кеш говорит "applied" — всё равно перепроверим быстро и честно
    if (is_array($state) && !empty($state['applied'])) {
        if (af_atf_templates_have_marks()) {
            return;
        }
        // кеш врёт / шаблоны менялись — сбрасываем
        if (is_object($cache)) {
            $cache->update('af_atf_tpl_state', ['applied' => 0, 'time' => TIME_NOW]);
        }
    }

    if (!af_atf_templates_have_marks()) {
        af_atf_apply_template_edits();
    }

    $ok = af_atf_templates_have_marks();
    if (is_object($cache)) {
        $cache->update('af_atf_tpl_state', ['applied' => ($ok ? 1 : 0), 'time' => TIME_NOW]);
    }
}
