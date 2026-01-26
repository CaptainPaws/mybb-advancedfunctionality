<?php
/**
 * AE Pack: lists — MyCode installer/uninstaller
 * Auto-called by AdvancedEditor pack installer:
 * - af_ae_lists_install_mycode()
 * - af_ae_lists_uninstall_mycode()
 *
 * IMPORTANT (MyBB 1.8.x):
 * Table `mycode` primary key column is `cid` (NOT `mid`).
 */

if (!defined('IN_MYBB')) {
    die('No direct access');
}

/**
 * Safe rebuild of MyCode cache (MyBB 1.8.x).
 */
if (!function_exists('af_ae_lists_rebuild_mycode_cache_safe')) {
    function af_ae_lists_rebuild_mycode_cache_safe(): void
    {
        if (!function_exists('rebuild_mycode_cache')) {
            $p = MYBB_ROOT . 'inc/functions_rebuild.php';
            if (is_file($p)) {
                require_once $p;
            }
        }

        if (function_exists('rebuild_mycode_cache')) {
            rebuild_mycode_cache();
        }
    }
}

/**
 * Upsert a MyCode by title (MyBB 1.8: table `mycode`, primary `cid`).
 */
if (!function_exists('af_ae_lists_mycode_upsert')) {
    function af_ae_lists_mycode_upsert(string $title, string $regex, string $replacement, int $parseorder, int $active = 1): void
    {
        global $db;

        if (!isset($db) || !is_object($db) || !method_exists($db, 'table_exists') || !$db->table_exists('mycode')) {
            return;
        }

        $title = trim($title);
        if ($title === '') {
            return;
        }

        $titleEsc = $db->escape_string($title);

        // MyBB 1.8.x: `cid`
        $q = $db->simple_select('mycode', 'cid', "title='{$titleEsc}'", ['limit' => 1]);
        $cid = (int)$db->fetch_field($q, 'cid');

        $row = [
            'title'       => $db->escape_string($title),
            'description' => $db->escape_string('AF AE Lists'),
            'regex'       => $db->escape_string($regex),
            'replacement' => $db->escape_string($replacement),
            'active'      => (int)$active,
            'parseorder'  => (int)$parseorder,
        ];

        if ($cid > 0) {
            $db->update_query('mycode', $row, "cid='{$cid}'");
        } else {
            $db->insert_query('mycode', $row);
        }
    }
}

/**
 * Delete MyCode rows by titles.
 */
if (!function_exists('af_ae_lists_mycode_delete_titles')) {
    function af_ae_lists_mycode_delete_titles(array $titles): void
    {
        global $db;

        if (!isset($db) || !is_object($db) || !method_exists($db, 'table_exists') || !$db->table_exists('mycode')) {
            return;
        }

        foreach ($titles as $t) {
            $t = trim((string)$t);
            if ($t === '') {
                continue;
            }
            $db->delete_query('mycode', "title='" . $db->escape_string($t) . "'");
        }
    }
}

/**
 * Install MyCodes for lists.
 *
 * Концепция:
 * - [li]...[/li] => <li>...</li>
 * - [ul]...[/ul] => <ul style="list-style-type:disc">...</ul>
 * - [ul=square]...[/ul] => <ul style="list-style-type:square">...</ul>
 * - [ul=i]...[/ul] и прочие => <ol style="...">...</ol>
 *
 * Да, это “ul параметризованный” превращаем в ol — потому что так у тебя задуманы кнопки/разметка.
 */
if (!function_exists('af_ae_lists_install_mycode')) {
    function af_ae_lists_install_mycode(): void
    {
        global $db;

        if (!isset($db) || !is_object($db) || !method_exists($db, 'table_exists') || !$db->table_exists('mycode')) {
            return;
        }

        // [li]...[/li]
        af_ae_lists_mycode_upsert(
            'AF AE: LI',
            '\\[li\\]([\\s\\S]*?)\\[\\/li\\]',
            '<li>$1</li>',
            40,
            1
        );

        // [ul]...[/ul] => disc list
        af_ae_lists_mycode_upsert(
            'AF AE: UL (disc)',
            '\\[ul\\]([\\s\\S]*?)\\[\\/ul\\]',
            '<ul style="list-style-type:disc; padding-left: 1.4em;">$1</ul>',
            20,
            1
        );

        // [ul=square]...[/ul]
        af_ae_lists_mycode_upsert(
            'AF AE: UL (square)',
            '\\[ul=square\\]([\\s\\S]*?)\\[\\/ul\\]',
            '<ul style="list-style-type:square; padding-left: 1.4em;">$1</ul>',
            20,
            1
        );

        // [ul=i]...[/ul] => decimal
        af_ae_lists_mycode_upsert(
            'AF AE: UL (decimal)',
            '\\[ul=i\\]([\\s\\S]*?)\\[\\/ul\\]',
            '<ol style="list-style-type:decimal; padding-left: 1.6em;">$1</ol>',
            20,
            1
        );

        // [ul=upper-roman]...[/ul]
        af_ae_lists_mycode_upsert(
            'AF AE: UL (upper-roman)',
            '\\[ul=upper-roman\\]([\\s\\S]*?)\\[\\/ul\\]',
            '<ol style="list-style-type:upper-roman; padding-left: 1.6em;">$1</ol>',
            20,
            1
        );

        // [ul=upper-alpha]...[/ul]
        af_ae_lists_mycode_upsert(
            'AF AE: UL (upper-alpha)',
            '\\[ul=upper-alpha\\]([\\s\\S]*?)\\[\\/ul\\]',
            '<ol style="list-style-type:upper-alpha; padding-left: 1.6em;">$1</ol>',
            20,
            1
        );

        // [ul=lower-alpha]...[/ul]
        af_ae_lists_mycode_upsert(
            'AF AE: UL (lower-alpha)',
            '\\[ul=lower-alpha\\]([\\s\\S]*?)\\[\\/ul\\]',
            '<ol style="list-style-type:lower-alpha; padding-left: 1.6em;">$1</ol>',
            20,
            1
        );

        af_ae_lists_rebuild_mycode_cache_safe();
    }
}


/**
 * Uninstall MyCodes for lists (and cleanup legacy titles if they were previously created).
 */
if (!function_exists('af_ae_lists_uninstall_mycode')) {
    function af_ae_lists_uninstall_mycode(): void
    {
        $titles = [
            // текущие
            'AF AE Lists: LI',
            'AF AE Lists: UL (disc)',
            'AF AE Lists: UL (square)',
            'AF AE Lists: UL (decimal)',
            'AF AE Lists: UL (upper-roman)',
            'AF AE Lists: UL (upper-alpha)',
            'AF AE Lists: UL (lower-alpha)',

            // легаси/мусор от прошлых попыток (на всякий случай)
            'AF AE: LI',
            'AF AE: UL (disc)',
            'AF AE: UL (square)',
            'AF AE: UL (decimal)',
            'AF AE: UL (upper-roman)',
            'AF AE: UL (upper-alpha)',
            'AF AE: UL (lower-alpha)',

            'AF AE: li',
            'AF AE: af_ul_disc',
            'AF AE: af_ul_square',
            'AF AE: af_ol_decimal',
            'AF AE: af_ol_upper_roman',
            'AF AE: af_ol_upper_alpha',
            'AF AE: af_ol_lower_alpha',
        ];

        af_ae_lists_mycode_delete_titles($titles);
        af_ae_lists_rebuild_mycode_cache_safe();
    }
}
