<?php

declare(strict_types=1);

if (!function_exists('af_ae_tables_parser_bundle')) {
    function af_ae_tables_parser_bundle(): array
    {
        return [
            'id'             => 'tables',
            'tags'           => ['table', 'tr', 'td', 'th'],
            'parse_callback' => 'af_ae_tables_parse',
        ];
    }

    function af_ae_tables_parse(string $message, ?object $parser = null, array $options = []): string
    {
        $pattern = af_ae_tables_table_pattern();
        $result = preg_replace_callback(
            $pattern,
            static function (array $match) use ($parser, $options): string {
                $attrString = $match[1] ?? '';
                $inner      = $match[2] ?? '';

                return af_ae_tables_render_table($attrString, $inner, $parser, $options);
            },
            $message
        );

        return is_string($result) ? $result : $message;
    }

    function af_ae_tables_table_pattern(): string
    {
        return '~\[table([^\]]*)\]((?:(?!\[/?table\b).|(?R))*)\[/table\]~is';
    }

    function af_ae_tables_trim(mixed $value): string
    {
        return trim((string) ($value ?? ''));
    }

    function af_ae_tables_normalize_align(?string $value): string
    {
        $value = strtolower(af_ae_tables_trim($value));

        return match ($value) {
            'left', 'right', 'center' => $value,
            default => 'center',
        };
    }

    function af_ae_tables_normalize_headers(?string $value): string
    {
        $value = strtolower(af_ae_tables_trim($value));

        if ($value === 'column') {
            $value = 'col';
        }

        return match ($value) {
            'col', 'row', 'both' => $value,
            default => 'none',
        };
    }

    function af_ae_tables_normalize_size(?string $value, string $fallback = '', bool $allowBlank = true): string
    {
        $value = af_ae_tables_trim($value);

        if ($value === '') {
            return $allowBlank ? '' : $fallback;
        }

        if (preg_match('~^\d+$~', $value)) {
            return $value . 'px';
        }

        if (preg_match('~^\d+(?:\.\d+)?(?:px|%|em|rem|vw|vh)$~i', $value)) {
            return $value;
        }

        return $fallback;
    }

    function af_ae_tables_normalize_width(?string $value, string $fallback = '100%'): string
    {
        $value = af_ae_tables_trim($value);

        if ($value === '') {
            return $fallback;
        }

        if (preg_match('~^\d+$~', $value)) {
            return $value . '%';
        }

        if (preg_match('~^\d+(?:\.\d+)?(?:%|px|em|rem|vw)$~i', $value)) {
            return $value;
        }

        return $fallback;
    }

    function af_ae_tables_is_border_enabled(?string $value): bool
    {
        $value = strtolower(af_ae_tables_trim($value));

        return !in_array($value, ['', '0', 'false', 'no'], true);
    }

    function af_ae_tables_parse_attr_string(string $raw): array
    {
        $attrs = [];

        if (preg_match_all('~([a-z_][a-z0-9_-]*)\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s\]]+))~i', $raw, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $key   = strtolower($match[1]);
                $value = $match[2] !== '' ? $match[2] : ($match[3] !== '' ? $match[3] : ($match[4] ?? ''));
                $attrs[$key] = $value;
            }
        }

        return $attrs;
    }

    function af_ae_tables_normalize_table_attrs(array $raw): array
    {
        $borderwidth = af_ae_tables_normalize_size($raw['borderwidth'] ?? '1px', '1px', false);
        $border      = af_ae_tables_trim($raw['border'] ?? '1');

        if ($borderwidth === '0px' || $borderwidth === '0') {
            $border = '0';
        }

        return [
            'width'       => af_ae_tables_normalize_width($raw['width'] ?? '100%', '100%'),
            'align'       => af_ae_tables_normalize_align($raw['align'] ?? 'center'),
            'headers'     => af_ae_tables_normalize_headers($raw['headers'] ?? ($raw['header'] ?? ($raw['heads'] ?? 'none'))),
            'cellwidth'   => af_ae_tables_normalize_size($raw['cellwidth'] ?? ($raw['rowwidth'] ?? ''), '', true),
            'bgcolor'     => af_ae_tables_trim($raw['bgcolor'] ?? ''),
            'textcolor'   => af_ae_tables_trim($raw['textcolor'] ?? ''),
            'hbgcolor'    => af_ae_tables_trim($raw['hbgcolor'] ?? ''),
            'htextcolor'  => af_ae_tables_trim($raw['htextcolor'] ?? ''),
            'border'      => $border,
            'bordercolor' => af_ae_tables_trim($raw['bordercolor'] ?? '#5f6670'),
            'borderwidth' => $borderwidth,
        ];
    }

    function af_ae_tables_escape_attr(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    function af_ae_tables_table_style(array $attrs): string
    {
        $styles = [
            'border-collapse:collapse',
            'table-layout:fixed',
            'max-width:100%',
        ];

        if ($attrs['width'] !== '') {
            $styles[] = 'width:' . $attrs['width'];
        }

        if ($attrs['align'] === 'left') {
            $styles[] = 'margin-left:0';
            $styles[] = 'margin-right:auto';
        } elseif ($attrs['align'] === 'right') {
            $styles[] = 'margin-left:auto';
            $styles[] = 'margin-right:0';
        } else {
            $styles[] = 'margin-left:auto';
            $styles[] = 'margin-right:auto';
        }

        return implode(';', $styles);
    }

    function af_ae_tables_cell_style(string $tagName, array $cellAttrs, array $tableAttrs): string
    {
        $styles = [
            'padding:8px 10px',
            'vertical-align:top',
        ];

        $width     = af_ae_tables_trim($cellAttrs['width'] ?? '');
        $align     = af_ae_tables_trim($cellAttrs['align'] ?? '');
        $bgcolor   = af_ae_tables_trim($cellAttrs['bgcolor'] ?? '');
        $textcolor = af_ae_tables_trim($cellAttrs['textcolor'] ?? '');

        if ($width === '' && $tableAttrs['cellwidth'] !== '') {
            $width = $tableAttrs['cellwidth'];
        }

        if ($width !== '') {
            $styles[] = 'width:' . af_ae_tables_normalize_size($width, '', true);
        }

        if ($align !== '') {
            $styles[] = 'text-align:' . af_ae_tables_normalize_align($align);
        }

        if ($bgcolor === '') {
            $bgcolor = $tagName === 'th' ? $tableAttrs['hbgcolor'] : $tableAttrs['bgcolor'];
        }

        if ($textcolor === '') {
            $textcolor = $tagName === 'th' ? $tableAttrs['htextcolor'] : $tableAttrs['textcolor'];
        }

        if ($bgcolor !== '') {
            $styles[] = 'background:' . $bgcolor;
        }

        if ($textcolor !== '') {
            $styles[] = 'color:' . $textcolor;
        }

        if (af_ae_tables_is_border_enabled($tableAttrs['border'])) {
            $styles[] = 'border:' . $tableAttrs['borderwidth'] . ' solid ' . $tableAttrs['bordercolor'];
        } else {
            $styles[] = 'border:none';
        }

        return implode(';', $styles);
    }

    function af_ae_tables_protect_nested_tables(string $content, ?object $parser = null, array $options = []): array
    {
        $tokens  = [];
        $pattern = af_ae_tables_table_pattern();

        $protected = preg_replace_callback(
            $pattern,
            static function (array $match) use (&$tokens, $parser, $options): string {
                $token = '%%AF_AE_TABLE_' . count($tokens) . '%%';
                $tokens[$token] = af_ae_tables_render_table(
                    $match[1] ?? '',
                    $match[2] ?? '',
                    $parser,
                    $options
                );

                return $token;
            },
            $content
        );

        return [is_string($protected) ? $protected : $content, $tokens];
    }

    function af_ae_tables_restore_tokens(string $content, array $tokens): string
    {
        return $tokens ? strtr($content, $tokens) : $content;
    }

    function af_ae_tables_extract_rows(string $content): array
    {
        $rows = [];

        if (preg_match_all('~\[tr([^\]]*)\](.*?)\[/tr\]~is', $content, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $rows[] = [
                    'attrs'   => af_ae_tables_parse_attr_string($match[1] ?? ''),
                    'content' => $match[2] ?? '',
                ];
            }
        }

        if (!$rows) {
            $rows[] = [
                'attrs'   => [],
                'content' => $content,
            ];
        }

        return $rows;
    }

    function af_ae_tables_extract_cells(string $content): array
    {
        $cells = [];

        if (preg_match_all('~\[(td|th)([^\]]*)\](.*?)\[/\1\]~is', $content, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $cells[] = [
                    'tag'     => strtolower($match[1] ?? 'td'),
                    'attrs'   => af_ae_tables_parse_attr_string($match[2] ?? ''),
                    'content' => $match[3] ?? '',
                ];
            }
        }

        if (!$cells) {
            $cells[] = [
                'tag'     => 'td',
                'attrs'   => [],
                'content' => $content,
            ];
        }

        return $cells;
    }

    function af_ae_tables_resolve_tag(string $explicitTag, array $tableAttrs, int $rowIndex, int $colIndex): string
    {
        if ($explicitTag === 'th') {
            return 'th';
        }

        $headers = $tableAttrs['headers'];

        if ($headers === 'both' && ($rowIndex === 0 || $colIndex === 0)) {
            return 'th';
        }

        if ($headers === 'col' && $rowIndex === 0) {
            return 'th';
        }

        if ($headers === 'row' && $colIndex === 0) {
            return 'th';
        }

        return 'td';
    }

    function af_ae_tables_render_cell_content(string $content, ?object $parser = null, array $options = [], array $tokens = []): string
    {
        $content = af_ae_tables_restore_tokens($content, $tokens);

        if (!empty($options['content_is_html'])) {
            return $content;
        }

        if ($parser && method_exists($parser, 'parse_message')) {
            $parseOptions = $options['cell_parse_options'] ?? [
                'allow_html'      => 0,
                'allow_mycode'    => 1,
                'allow_smilies'   => 1,
                'allow_imgcode'   => 1,
                'allow_videocode' => 1,
                'filter_badwords' => 1,
            ];

            $parsed = $parser->parse_message($content, $parseOptions);

            return af_ae_tables_restore_tokens((string) $parsed, $tokens);
        }

        $escaped = nl2br(
            htmlspecialchars($content, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
        );

        return af_ae_tables_restore_tokens($escaped, $tokens);
    }

    function af_ae_tables_render_cell(
        string $tagName,
        array $cellAttrs,
        string $content,
        array $tableAttrs,
        ?object $parser = null,
        array $options = [],
        array $tokens = []
    ): string {
        $attrHtml = [
            'class="af-bb-cell af-bb-cell--' . af_ae_tables_escape_attr($tagName) . '"',
            'style="' . af_ae_tables_escape_attr(af_ae_tables_cell_style($tagName, $cellAttrs, $tableAttrs)) . '"',
        ];

        $colspan = (int) ($cellAttrs['colspan'] ?? 0);
        $rowspan = (int) ($cellAttrs['rowspan'] ?? 0);

        if ($colspan > 1) {
            $attrHtml[] = 'colspan="' . $colspan . '"';
        }

        if ($rowspan > 1) {
            $attrHtml[] = 'rowspan="' . $rowspan . '"';
        }

        $innerHtml = af_ae_tables_render_cell_content($content, $parser, $options, $tokens);
        if (af_ae_tables_trim($innerHtml) === '') {
            $innerHtml = '&nbsp;';
        }

        return '<' . $tagName . ' ' . implode(' ', $attrHtml) . '>' . $innerHtml . '</' . $tagName . '>';
    }

    function af_ae_tables_render_table(
        string $rawAttrs,
        string $content,
        ?object $parser = null,
        array $options = []
    ): string {
        $tableAttrs = af_ae_tables_normalize_table_attrs(
            af_ae_tables_parse_attr_string($rawAttrs)
        );

        [$protectedContent, $tokens] = af_ae_tables_protect_nested_tables($content, $parser, $options);
        $rows = af_ae_tables_extract_rows($protectedContent);

        $rowsHtml = [];

        foreach ($rows as $rowIndex => $row) {
            $cells = af_ae_tables_extract_cells($row['content'] ?? '');
            $cellHtml = [];

            foreach ($cells as $colIndex => $cell) {
                $tagName = af_ae_tables_resolve_tag(
                    strtolower((string) ($cell['tag'] ?? 'td')),
                    $tableAttrs,
                    $rowIndex,
                    $colIndex
                );

                $cellHtml[] = af_ae_tables_render_cell(
                    $tagName,
                    $cell['attrs'] ?? [],
                    (string) ($cell['content'] ?? ''),
                    $tableAttrs,
                    $parser,
                    $options,
                    $tokens
                );
            }

            $rowsHtml[] = '<tr>' . implode('', $cellHtml) . '</tr>';
        }

        return
            '<div class="af-bb-table-wrap">' .
                '<table class="af-bb-table" style="' . af_ae_tables_escape_attr(af_ae_tables_table_style($tableAttrs)) . '">' .
                    '<tbody>' . implode('', $rowsHtml) . '</tbody>' .
                '</table>' .
            '</div>';
    }
}
if (!function_exists('af_ae_tables_bridge_protect_bbcodes')) {
    function af_ae_tables_bridge_protect_bbcodes(string $message, array &$protected): string
    {
        $protected = [];

        $result = preg_replace_callback(
            '~\[(code|php)\b[^\]]*\].*?\[/\1\]~is',
            static function (array $match) use (&$protected): string {
                $key = '%%AF_AE_TABLES_BBCODE_' . count($protected) . '%%';
                $protected[$key] = $match[0];

                return $key;
            },
            $message
        );

        return is_string($result) ? $result : $message;
    }

    function af_ae_tables_bridge_restore_bbcodes(string $message, array $protected): string
    {
        return $protected ? strtr($message, $protected) : $message;
    }

    function af_ae_tables_bridge_prefix_tags(string $message): string
    {
        if ($message === '' || stripos($message, '[table') === false) {
            return $message;
        }

        $protected = [];
        $message = af_ae_tables_bridge_protect_bbcodes($message, $protected);

        $message = preg_replace('~\[(/?)table\b([^\]]*)\]~i', '[$1af_table$2]', $message);
        $message = preg_replace('~\[(/?)tr\b([^\]]*)\]~i', '[$1af_tr$2]', $message);
        $message = preg_replace('~\[(/?)td\b([^\]]*)\]~i', '[$1af_td$2]', $message);
        $message = preg_replace('~\[(/?)th\b([^\]]*)\]~i', '[$1af_th$2]', $message);

        if (!is_string($message)) {
            return '';
        }

        return af_ae_tables_bridge_restore_bbcodes($message, $protected);
    }

    function af_ae_tables_bridge_unprefix_tags(string $message): string
    {
        if ($message === '' || (stripos($message, '[af_table') === false && stripos($message, '[table') === false)) {
            return $message;
        }

        $message = preg_replace('~\[(/?)af_table\b([^\]]*)\]~i', '[$1table$2]', $message);
        $message = preg_replace('~\[(/?)af_tr\b([^\]]*)\]~i', '[$1tr$2]', $message);
        $message = preg_replace('~\[(/?)af_td\b([^\]]*)\]~i', '[$1td$2]', $message);
        $message = preg_replace('~\[(/?)af_th\b([^\]]*)\]~i', '[$1th$2]', $message);

        return is_string($message) ? $message : '';
    }

    function af_ae_tables_bridge_parse_html(string $message): string
    {
        if ($message === '') {
            return $message;
        }

        $message = af_ae_tables_bridge_unprefix_tags($message);

        return af_ae_tables_parse($message, null, [
            'content_is_html' => true,
        ]);
    }

    /**
     * Legacy bridge hooks: singular names
     */
    function af_ae_bbcode_table_parse_start(&$message): void
    {
        if (!is_string($message) || $message === '') {
            return;
        }

        $message = af_ae_tables_bridge_prefix_tags($message);
    }

    function af_ae_bbcode_table_parse_end(&$message): void
    {
        if (!is_string($message) || $message === '') {
            return;
        }

        $message = af_ae_tables_bridge_parse_html($message);
    }

    function af_ae_bbcode_table_parse(&$message): void
    {
        if (!is_string($message) || $message === '') {
            return;
        }

        $message = af_ae_tables_bridge_parse_html($message);
    }

    /**
     * Legacy bridge hooks: plural names
     */
    function af_ae_bbcode_tables_parse_start(&$message): void
    {
        af_ae_bbcode_table_parse_start($message);
    }

    function af_ae_bbcode_tables_parse_end(&$message): void
    {
        af_ae_bbcode_table_parse_end($message);
    }

    function af_ae_bbcode_tables_parse(&$message): void
    {
        af_ae_bbcode_table_parse($message);
    }

    /**
     * Quick Reply / AQR aliases
     */
    function af_aqr_bbcode_table_parse_start(&$message): void
    {
        af_ae_bbcode_table_parse_start($message);
    }

    function af_aqr_bbcode_table_parse_end(&$message): void
    {
        af_ae_bbcode_table_parse_end($message);
    }

    function af_aqr_bbcode_table_parse(&$message): void
    {
        af_ae_bbcode_table_parse($message);
    }

    function af_aqr_bbcode_tables_parse_start(&$message): void
    {
        af_ae_bbcode_table_parse_start($message);
    }

    function af_aqr_bbcode_tables_parse_end(&$message): void
    {
        af_ae_bbcode_table_parse_end($message);
    }

    function af_aqr_bbcode_tables_parse(&$message): void
    {
        af_ae_bbcode_table_parse($message);
    }
}

return af_ae_tables_parser_bundle();