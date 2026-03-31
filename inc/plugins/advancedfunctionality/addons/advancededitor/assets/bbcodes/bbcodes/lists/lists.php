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
 * - [ul=upper-alpha]...[/ul] => <ol style="list-style-type:upper-alpha">...</ol>
 * - [ol]/[ol=...]...[/ol] поддерживаются как каноничные ordered-теги
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

        // [ul=circle]...[/ul]
        af_ae_lists_mycode_upsert(
            'AF AE: UL (circle)',
            '\\[ul=circle\\]([\\s\\S]*?)\\[\\/ul\\]',
            '<ul style="list-style-type:circle; padding-left: 1.4em;">$1</ul>',
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

        // [ul=lower-roman]...[/ul]
        af_ae_lists_mycode_upsert(
            'AF AE: UL (lower-roman)',
            '\\[ul=lower-roman\\]([\\s\\S]*?)\\[\\/ul\\]',
            '<ol style="list-style-type:lower-roman; padding-left: 1.6em;">$1</ol>',
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

        // [ol]...[/ol] => decimal
        af_ae_lists_mycode_upsert(
            'AF AE: OL (decimal default)',
            '\\[ol\\]([\\s\\S]*?)\\[\\/ol\\]',
            '<ol style="list-style-type:decimal; padding-left: 1.6em;">$1</ol>',
            20,
            1
        );

        // [ol=decimal|upper-alpha|upper-roman|lower-alpha|lower-roman|i]...[/ol]
        af_ae_lists_mycode_upsert(
            'AF AE: OL (typed)',
            '\\[ol=(decimal|upper-alpha|upper-roman|lower-alpha|lower-roman|i)\\]([\\s\\S]*?)\\[\\/ol\\]',
            '<ol style="list-style-type:$1; padding-left: 1.6em;">$2</ol>',
            20,
            1
        );

        // Backward compatibility: [ol=i] should render as decimal
        af_ae_lists_mycode_upsert(
            'AF AE: OL (i->decimal)',
            '\\[ol=i\\]([\\s\\S]*?)\\[\\/ol\\]',
            '<ol style="list-style-type:decimal; padding-left: 1.6em;">$1</ol>',
            19,
            1
        );

        af_ae_lists_rebuild_mycode_cache_safe();
    }
}

if (!function_exists('af_ae_lists_bridge_protect_bbcodes')) {
    function af_ae_lists_bridge_protect_bbcodes(string $message, array &$protected): string
    {
        $protected = [];

        $result = preg_replace_callback(
            '~\[(code|php)\b[^\]]*\].*?\[/\1\]~is',
            static function (array $match) use (&$protected): string {
                $key = '%%AF_AE_LISTS_BBCODE_' . count($protected) . '%%';
                $protected[$key] = $match[0];

                return $key;
            },
            $message
        );

        return is_string($result) ? $result : $message;
    }

    function af_ae_lists_bridge_restore_bbcodes(string $message, array $protected): string
    {
        return $protected ? strtr($message, $protected) : $message;
    }

    function af_ae_lists_bridge_prefix_tags(string $message): string
    {
        if ($message === '' || stripos($message, '[ul') === false && stripos($message, '[ol') === false && stripos($message, '[li') === false) {
            return $message;
        }

        $protected = [];
        $message = af_ae_lists_bridge_protect_bbcodes($message, $protected);

        $message = preg_replace('~\[(/?)ul\b([^\]]*)\]~i', '[$1af_ul$2]', $message);
        $message = preg_replace('~\[(/?)ol\b([^\]]*)\]~i', '[$1af_ol$2]', $message);
        $message = preg_replace('~\[(/?)li\b([^\]]*)\]~i', '[$1af_li$2]', $message);

        if (!is_string($message)) {
            return '';
        }

        return af_ae_lists_bridge_restore_bbcodes($message, $protected);
    }

    function af_ae_lists_bridge_normalize_list_attr(string $tagName, string $rawAttr): array
    {
        $attr = strtolower(trim($rawAttr));
        if ($attr === 'i') {
            $attr = 'decimal';
        }

        if ($tagName === 'af_ol') {
            $allowed = ['decimal', 'upper-alpha', 'upper-roman', 'lower-alpha', 'lower-roman'];
            if (!in_array($attr, $allowed, true)) {
                $attr = 'decimal';
            }

            return [
                'tag' => 'ol',
                'style' => $attr,
                'padding' => '1.6em',
            ];
        }

        if ($attr === '' || $attr === 'disc') {
            return [
                'tag' => 'ul',
                'style' => 'disc',
                'padding' => '1.4em',
            ];
        }

        if (in_array($attr, ['circle', 'square'], true)) {
            return [
                'tag' => 'ul',
                'style' => $attr,
                'padding' => '1.4em',
            ];
        }

        $ordered = ['decimal', 'upper-alpha', 'upper-roman', 'lower-alpha', 'lower-roman'];
        if (in_array($attr, $ordered, true)) {
            return [
                'tag' => 'ol',
                'style' => $attr,
                'padding' => '1.6em',
            ];
        }

        return [
            'tag' => 'ul',
            'style' => 'disc',
            'padding' => '1.4em',
        ];
    }

    function af_ae_lists_bridge_render_node(array $node): string
    {
        $type = $node['type'] ?? 'text';

        if ($type === 'text') {
            return (string)($node['value'] ?? '');
        }

        $children = '';
        foreach ($node['children'] ?? [] as $child) {
            if (is_array($child)) {
                $children .= af_ae_lists_bridge_render_node($child);
            }
        }

        if ($type === 'af_li') {
            return '<li>' . $children . '</li>';
        }

        if ($type === 'af_ul' || $type === 'af_ol') {
            $normalized = af_ae_lists_bridge_normalize_list_attr($type, (string)($node['attr'] ?? ''));
            $tag = $normalized['tag'];
            $style = htmlspecialchars($normalized['style'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $padding = htmlspecialchars($normalized['padding'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

            return '<' . $tag . ' style="list-style-type:' . $style . '; padding-left:' . $padding . ';">' . $children . '</' . $tag . '>';
        }

        return $children;
    }

    function af_ae_lists_bridge_parse_html(string $message): string
    {
        if ($message === '' || (stripos($message, '[af_ul') === false && stripos($message, '[af_ol') === false && stripos($message, '[af_li') === false)) {
            return $message;
        }

        $pattern = '~\[(\/?)(af_ul|af_ol|af_li)(?:=([^\]]*))?\]~i';
        if (!preg_match_all($pattern, $message, $matches, PREG_OFFSET_CAPTURE)) {
            return $message;
        }

        $root = ['type' => 'root', 'children' => []];
        $stack = [&$root];
        $lastOffset = 0;
        $tokenCount = count($matches[0]);

        for ($i = 0; $i < $tokenCount; $i++) {
            $fullToken = $matches[0][$i][0];
            $tokenOffset = (int)$matches[0][$i][1];

            if ($tokenOffset > $lastOffset) {
                $text = substr($message, $lastOffset, $tokenOffset - $lastOffset);
                if ($text !== '') {
                    $stack[count($stack) - 1]['children'][] = [
                        'type' => 'text',
                        'value' => $text,
                    ];
                }
            }

            $isClosing = trim(strtolower((string)$matches[1][$i][0])) === '/';
            $tagName = strtolower((string)$matches[2][$i][0]);
            $attr = isset($matches[3][$i][0]) ? (string)$matches[3][$i][0] : '';

            if (!$isClosing) {
                $node = [
                    'type' => $tagName,
                    'attr' => $attr,
                    'children' => [],
                ];

                $stack[count($stack) - 1]['children'][] = $node;
                $newIndex = count($stack[count($stack) - 1]['children']) - 1;
                $stack[] = &$stack[count($stack) - 1]['children'][$newIndex];
            } else {
                $found = -1;
                for ($j = count($stack) - 1; $j > 0; $j--) {
                    if (($stack[$j]['type'] ?? '') === $tagName) {
                        $found = $j;
                        break;
                    }
                }

                if ($found !== -1) {
                    while (count($stack) - 1 >= $found) {
                        array_pop($stack);
                    }
                } else {
                    $stack[count($stack) - 1]['children'][] = [
                        'type' => 'text',
                        'value' => $fullToken,
                    ];
                }
            }

            $lastOffset = $tokenOffset + strlen($fullToken);
        }

        if ($lastOffset < strlen($message)) {
            $tail = substr($message, $lastOffset);
            if ($tail !== '') {
                $stack[count($stack) - 1]['children'][] = [
                    'type' => 'text',
                    'value' => $tail,
                ];
            }
        }

        return af_ae_lists_bridge_render_node($root);
    }

    function af_ae_bbcode_lists_parse_start(&$message): void
    {
        if (!is_string($message) || $message === '') {
            return;
        }

        $message = af_ae_lists_bridge_prefix_tags($message);
    }

    function af_ae_bbcode_lists_parse_end(&$message): void
    {
        if (!is_string($message) || $message === '') {
            return;
        }

        $message = af_ae_lists_bridge_parse_html($message);
    }

    function af_ae_bbcode_lists_parse(&$message): void
    {
        af_ae_bbcode_lists_parse_end($message);
    }

    function af_aqr_bbcode_lists_parse_start(&$message): void
    {
        af_ae_bbcode_lists_parse_start($message);
    }

    function af_aqr_bbcode_lists_parse_end(&$message): void
    {
        af_ae_bbcode_lists_parse_end($message);
    }

    function af_aqr_bbcode_lists_parse(&$message): void
    {
        af_ae_bbcode_lists_parse($message);
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
            'AF AE Lists: UL (circle)',
            'AF AE Lists: UL (decimal)',
            'AF AE Lists: UL (upper-roman)',
            'AF AE Lists: UL (upper-alpha)',
            'AF AE Lists: UL (lower-roman)',
            'AF AE Lists: UL (lower-alpha)',
            'AF AE Lists: OL (decimal default)',
            'AF AE Lists: OL (typed)',
            'AF AE Lists: OL (i->decimal)',

            // легаси/мусор от прошлых попыток (на всякий случай)
            'AF AE: LI',
            'AF AE: UL (disc)',
            'AF AE: UL (square)',
            'AF AE: UL (circle)',
            'AF AE: UL (decimal)',
            'AF AE: UL (upper-roman)',
            'AF AE: UL (upper-alpha)',
            'AF AE: UL (lower-roman)',
            'AF AE: UL (lower-alpha)',
            'AF AE: OL (decimal default)',
            'AF AE: OL (typed)',
            'AF AE: OL (i->decimal)',

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
