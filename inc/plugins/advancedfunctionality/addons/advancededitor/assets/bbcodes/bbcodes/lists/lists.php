<?php
/**
 * AE Pack: lists — canonical list parsing/rendering for AE + MyBB output.
 */

if (!defined('IN_MYBB')) {
    die('No direct access');
}

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

if (!function_exists('af_ae_lists_html_type_attr')) {
    function af_ae_lists_html_type_attr(string $tag, string $style): string
    {
        $tag = strtolower(trim($tag));
        $style = strtolower(trim($style));

        if ($tag === 'ul') {
            return in_array($style, ['disc', 'circle', 'square'], true) ? $style : '';
        }

        if ($tag === 'ol') {
            return match ($style) {
                'decimal' => '1',
                'upper-alpha' => 'A',
                'lower-alpha' => 'a',
                'upper-roman' => 'I',
                'lower-roman' => 'i',
                default => '',
            };
        }

        return '';
    }
}

if (!function_exists('af_ae_lists_render_html')) {
    function af_ae_lists_render_html(string $tag, string $style, string $content): string
    {
        $tag = strtolower(trim($tag)) === 'ol' ? 'ol' : 'ul';
        $style = strtolower(trim($style));

        if ($tag === 'ul' && !in_array($style, ['disc', 'circle', 'square'], true)) {
            $style = 'disc';
        }

        if ($tag === 'ol' && !in_array($style, ['decimal', 'upper-alpha', 'lower-alpha', 'upper-roman', 'lower-roman'], true)) {
            $style = 'decimal';
        }

        $padding = $tag === 'ol' ? '1.6em' : '1.4em';
        $typeAttr = af_ae_lists_html_type_attr($tag, $style);
        $class = 'af-ae-list af-ae-list--' . preg_replace('~[^a-z0-9_-]+~i', '-', $style);

        return '<' . $tag .
            ' class="' . htmlspecialchars($class, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '"' .
            ' data-af-list-type="' . $tag . '"' .
            ' data-af-list-style="' . htmlspecialchars($style, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '"' .
            ' data-list="' . htmlspecialchars($style, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '"' .
            ($typeAttr !== '' ? ' type="' . htmlspecialchars($typeAttr, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '"' : '') .
            ' style="list-style-type:' . htmlspecialchars($style, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '; padding-left:' . $padding . ';">' .
            $content .
            '</' . $tag . '>';
    }
}

if (!function_exists('af_ae_lists_install_mycode')) {
    function af_ae_lists_install_mycode(): void
    {
        global $db;

        if (!isset($db) || !is_object($db) || !method_exists($db, 'table_exists') || !$db->table_exists('mycode')) {
            return;
        }

        af_ae_lists_mycode_upsert(
            'AF AE: LI',
            '\\[li\\]([\\s\\S]*?)\\[\\/li\\]',
            '<li class="af-ae-list__item" style="list-style-type:inherit;">$1</li>',
            40,
            1
        );

        af_ae_lists_mycode_upsert(
            'AF AE: UL (disc)',
            '\\[ul\\]([\\s\\S]*?)\\[\\/ul\\]',
            af_ae_lists_render_html('ul', 'disc', '$1'),
            20,
            1
        );

        af_ae_lists_mycode_upsert(
            'AF AE: UL (typed)',
            '\\[ul=(circle|square)\\]([\\s\\S]*?)\\[\\/ul\\]',
            af_ae_lists_render_html('ul', '$1', '$2'),
            20,
            1
        );

        af_ae_lists_mycode_upsert(
            'AF AE: OL (decimal default)',
            '\\[ol\\]([\\s\\S]*?)\\[\\/ol\\]',
            af_ae_lists_render_html('ol', 'decimal', '$1'),
            20,
            1
        );

        af_ae_lists_mycode_upsert(
            'AF AE: OL (typed)',
            '\\[ol=(decimal|upper-alpha|upper-roman|lower-alpha|lower-roman)\\]([\\s\\S]*?)\\[\\/ol\\]',
            af_ae_lists_render_html('ol', '$1', '$2'),
            20,
            1
        );

        // Legacy compat: [ol=i] / old packs with ordered styles under [ul=...]
        af_ae_lists_mycode_upsert(
            'AF AE: OL (i->decimal)',
            '\\[ol=i\\]([\\s\\S]*?)\\[\\/ol\\]',
            af_ae_lists_render_html('ol', 'decimal', '$1'),
            19,
            1
        );

        af_ae_lists_mycode_upsert(
            'AF AE: UL legacy ordered',
            '\\[ul=(i|decimal|upper-alpha|upper-roman|lower-alpha|lower-roman)\\]([\\s\\S]*?)\\[\\/ul\\]',
            af_ae_lists_render_html('ol', '$1', '$2'),
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
        if ($message === '' || (stripos($message, '[ul') === false && stripos($message, '[ol') === false && stripos($message, '[li') === false)) {
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
        $tagName = strtolower(trim($tagName));
        $attr = strtolower(trim($rawAttr));

        if ($attr === 'i') {
            $attr = 'decimal';
        }

        if ($tagName === 'af_ol') {
            if (!in_array($attr, ['decimal', 'upper-alpha', 'upper-roman', 'lower-alpha', 'lower-roman'], true)) {
                $attr = 'decimal';
            }

            return ['tag' => 'ol', 'style' => $attr];
        }

        // af_ul canonical and legacy compatibility
        if ($attr === '' || $attr === 'disc') {
            return ['tag' => 'ul', 'style' => 'disc'];
        }

        if (in_array($attr, ['circle', 'square'], true)) {
            return ['tag' => 'ul', 'style' => $attr];
        }

        if (in_array($attr, ['decimal', 'upper-alpha', 'upper-roman', 'lower-alpha', 'lower-roman'], true)) {
            return ['tag' => 'ol', 'style' => $attr];
        }

        return ['tag' => 'ul', 'style' => 'disc'];
    }

    function af_ae_lists_bridge_is_spacer_html(string $html): bool
    {
        $probe = str_ireplace(['<br>', '<br/>', '<br />', '&nbsp;'], '', $html);
        $probe = preg_replace('~\s+~u', '', (string)$probe);
        return trim(strip_tags((string)$probe)) === '';
    }

    function af_ae_lists_bridge_cleanup_rendered_html(string $html): string
    {
        $html = preg_replace('~(<(?:ul|ol)\b[^>]*>)(?:\s|&nbsp;|<br\s*/?>)+~i', '$1', $html);
        $html = preg_replace('~(?:\s|&nbsp;|<br\s*/?>)+(</(?:ul|ol)>)~i', '$1', $html);
        $html = preg_replace('~</li>(?:\s|&nbsp;|<br\s*/?>)+(?=<li\b)~i', '</li>', $html);

        return is_string($html) ? $html : '';
    }

    function af_ae_lists_bridge_render_node(array $node, array $context = []): string
    {
        $type = $node['type'] ?? 'text';

        if ($type === 'root') {
            $html = '';
            foreach ($node['children'] ?? [] as $child) {
                if (is_array($child)) {
                    $html .= af_ae_lists_bridge_render_node($child, $context);
                }
            }
            return af_ae_lists_bridge_cleanup_rendered_html($html);
        }

        if ($type === 'text') {
            $value = (string)($node['value'] ?? '');
            if (!empty($context['inside_list']) && af_ae_lists_bridge_is_spacer_html($value)) {
                return '';
            }
            return $value;
        }

        if ($type === 'af_li') {
            $children = '';
            foreach ($node['children'] ?? [] as $child) {
                if (is_array($child)) {
                    $children .= af_ae_lists_bridge_render_node($child, $context);
                }
            }

            $children = af_ae_lists_bridge_cleanup_rendered_html($children);
            $attrs = ' class="af-ae-list__item" style="list-style-type:inherit;"';

            if (!empty($context['list_style'])) {
                $attrs .= ' data-af-list-style="' . htmlspecialchars((string)$context['list_style'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '"';
            }

            return '<li' . $attrs . '>' . $children . '</li>';
        }

        if ($type === 'af_ul' || $type === 'af_ol') {
            $normalized = af_ae_lists_bridge_normalize_list_attr($type, (string)($node['attr'] ?? ''));
            $children = '';
            $childContext = ['inside_list' => true, 'list_style' => $normalized['style']];

            foreach ($node['children'] ?? [] as $child) {
                if (is_array($child)) {
                    $children .= af_ae_lists_bridge_render_node($child, $childContext);
                }
            }

            return af_ae_lists_render_html($normalized['tag'], $normalized['style'], af_ae_lists_bridge_cleanup_rendered_html($children));
        }

        $children = '';
        foreach ($node['children'] ?? [] as $child) {
            if (is_array($child)) {
                $children .= af_ae_lists_bridge_render_node($child, $context);
            }
        }

        return $children;
    }

    function af_ae_lists_bridge_parse_html(string $message): string
    {
        if ($message === '' || (stripos($message, '[af_ul') === false && stripos($message, '[af_ol') === false && stripos($message, '[af_li') === false)) {
            return $message;
        }

        $pattern = '~\[(/?)(af_ul|af_ol|af_li)(?:=([^\]]*))?\]~i';
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
                    $stack[count($stack) - 1]['children'][] = ['type' => 'text', 'value' => $text];
                }
            }

            $isClosing = trim(strtolower((string)$matches[1][$i][0])) === '/';
            $tagName = strtolower((string)$matches[2][$i][0]);
            $attr = isset($matches[3][$i][0]) ? (string)$matches[3][$i][0] : '';

            if (!$isClosing) {
                $node = ['type' => $tagName, 'attr' => $attr, 'children' => []];
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
                    $stack[count($stack) - 1]['children'][] = ['type' => 'text', 'value' => $fullToken];
                }
            }

            $lastOffset = $tokenOffset + strlen($fullToken);
        }

        if ($lastOffset < strlen($message)) {
            $tail = substr($message, $lastOffset);
            if ($tail !== '') {
                $stack[count($stack) - 1]['children'][] = ['type' => 'text', 'value' => $tail];
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

if (!function_exists('af_ae_lists_uninstall_mycode')) {
    function af_ae_lists_uninstall_mycode(): void
    {
        $titles = [
            'AF AE: LI',
            'AF AE: UL (disc)',
            'AF AE: UL (typed)',
            'AF AE: UL legacy ordered',
            'AF AE: OL (decimal default)',
            'AF AE: OL (typed)',
            'AF AE: OL (i->decimal)',

            // old titles
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
        ];

        af_ae_lists_mycode_delete_titles($titles);
        af_ae_lists_rebuild_mycode_cache_safe();
    }
}
