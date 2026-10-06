<?php
/** Metadata compiler boundary. No filesystem or AJAX work is needed on a click. */
function af_advancededitor_shell_icon_url(string $name): string
{
    foreach (['assets/img/', 'assets/img/img/'] as $base) {
        if (is_file(__DIR__ . '/' . $base . basename($name))) {
            return af_advancededitor_addon_file_url($base . basename($name));
        }
    }
    return '';
}

function af_advancededitor_shell_fa_icon(string $cmd): string
{
    $svgOwned = [
        'horizontalrule','subscript','superscript',
        'af_mark','af_abbr','af_tabs','af_accordion',
        'af_ul_disc','af_ul_square','af_ul_decimal',
        'af_ul_upper_roman','af_ul_upper_alpha','af_ul_lower_alpha',
    ];
    if (in_array($cmd, $svgOwned, true)) return '';

    $map = [
        'bulletlist' => 'fa-solid fa-list-ul',
        'orderedlist' => 'fa-solid fa-list-ol',
        'left' => 'fa-solid fa-align-left',
        'center' => 'fa-solid fa-align-center',
        'right' => 'fa-solid fa-align-right',
        'justify' => 'fa-solid fa-align-justify',
        'quote' => 'fa-solid fa-quote-right',
        'code' => 'fa-solid fa-code',
        'link' => 'fa-solid fa-link',
        'unlink' => 'fa-solid fa-link-slash',
        'image' => 'fa-solid fa-image',
        'youtube' => 'fa-brands fa-youtube',
        'emoticon' => 'fa-regular fa-face-smile',
        'maximize' => 'fa-solid fa-expand',
        'af_togglemode' => 'fa-solid fa-code',
        'af_tables' => 'fa-solid fa-table-cells',
        'af_indent' => 'fa-solid fa-indent',
        'af_floatbb' => 'fa-solid fa-align-left',
        'af_htmlbb' => 'fa-solid fa-file-code',
        'af_tquote' => 'fa-solid fa-quote-left',
        'af_anchor' => 'fa-solid fa-anchor',
        'af_anchorlink' => 'fa-solid fa-link',
        'af_font' => 'fa-solid fa-font',
        'af_fontsize' => 'fa-solid fa-text-height',
        'af_embedvideos' => 'fa-solid fa-video',
        'af_lockcontent' => 'fa-solid fa-lock',
        'af_resizeimg' => 'fa-solid fa-up-right-and-down-left-from-center',
        'af_spoiler' => 'fa-solid fa-eye-slash',
        'af_drafts' => 'fa-solid fa-file-pen',
        'af_stikers' => 'fa-regular fa-face-laugh',
        'af_kb_insert' => 'fa-solid fa-book-open',
        'af_formathelp' => 'fa-regular fa-circle-question',
    ];
    if (isset($map[$cmd])) return $map[$cmd];
    return str_starts_with($cmd, 'af_') ? 'fa-solid fa-code' : '';
}

function af_advancededitor_shell_command_key(string $cmd, array $button = []): string
{
    $cmd = strtolower(trim($cmd));
    $handler = strtolower(trim((string)($button['handler'] ?? '')));
    $capability = strtolower(trim((string)($button['capability'] ?? '')));
    $title = trim((string)($button['title'] ?? ''));
    $label = trim((string)($button['label'] ?? ''));
    $isKbLabel = strcasecmp($title, 'KB') === 0
        || strcasecmp($label, 'KB') === 0
        || strcasecmp($title, 'Insert KB') === 0
        || $title === 'Вставить KB';
    if ($handler === 'kb_insert'
        || $capability === 'kb-insert'
        || $isKbLabel
        || in_array($cmd, ['kb','kb_insert','af_kb','af_kb_insert','knowledgebase','knowledgebase_insert'], true)) {
        return 'af_kb_insert';
    }
    return $cmd;
}

function af_advancededitor_shell_registry(array $available, array $custom, array $packs): array
{
    $buttons = [];
    $capabilities = [];
    $tags = [
        'bold' => ['[b]', '[/b]'], 'italic' => ['[i]', '[/i]'],
        'underline' => ['[u]', '[/u]'], 'strike' => ['[s]', '[/s]'],
        'subscript' => ['[sub]', '[/sub]'], 'superscript' => ['[sup]', '[/sup]'],
        'quote' => ['[quote]', '[/quote]'], 'code' => ['[code]', '[/code]'],
        'link' => ['[url]', '[/url]'], 'image' => ['[img]', '[/img]'],
        'email' => ['[email]', '[/email]'], 'youtube' => ['[video=youtube]', '[/video]'],
        'bulletlist' => ["[list]\n[*]", "\n[/list]"],
        'orderedlist' => ["[list=1]\n[*]", "\n[/list]"],
        'left' => ['[align=left]', '[/align]'], 'center' => ['[align=center]', '[/align]'],
        'right' => ['[align=right]', '[/align]'], 'justify' => ['[align=justify]', '[/align]'],
        'horizontalrule' => ['[hr]', ''],
        'spoiler' => ['[spoiler]', '[/spoiler]'],
    ];
    foreach ($available as $b) {
        $cmd = (string)($b['cmd'] ?? '');
        if ($cmd === '' || $cmd === '|') continue;
        $buttons[$cmd] = $b;
        if (isset($tags[$cmd])) {
            [$buttons[$cmd]['opentag'], $buttons[$cmd]['closetag']] = $tags[$cmd];
        }
    }
    $buttons['spoiler'] = ['cmd' => 'spoiler', 'label' => 'Spoiler', 'title' => 'Спойлер', 'opentag' => '[spoiler]', 'closetag' => '[/spoiler]'];
    foreach ($packs['packs'] as $id => $pack) {
        $runtime = $pack['runtime'];
        $capabilities[$id] = [
            'activation' => $runtime['activation'] ?? 'click',
            'requires' => array_values((array)($runtime['requires'] ?? [])),
            'js' => $pack['assets']['js'], 'css' => $pack['assets']['css'],
            'triggers' => array_values((array)($runtime['triggers'] ?? [])),
        ];
        foreach ($pack['buttons'] as $b) {
            // Pack commands must activate their runtime before invoking its handler.
            // Merely emitting the button without a capability caused first clicks
            // to fail because the corresponding feature JS was never requested.
            if (empty($b['capability']) && !empty($b['handler'])) {
                $b['capability'] = $id;
            }
            $buttons[$b['cmd']] = array_merge($buttons[$b['cmd']] ?? [], $b);
        }
        foreach ((array)($runtime['commands'] ?? []) as $cmd) {
            $prototype = $pack['buttons'][0] ?? [];
            $buttons[$cmd] = array_merge($buttons[$cmd] ?? ['cmd' => $cmd], [
                'capability' => $id, 'handler' => $prototype['handler'] ?? $id,
                'icon' => $prototype['icon'] ?? '',
            ]);
        }
    }
    foreach ($custom as $b) {
        $cmd = $b['cmd'];
        $buttons[$cmd] = array_merge($buttons[$cmd] ?? [], $b);
    }
    $capabilities = array_merge($capabilities, (array)($GLOBALS['af_ae_external_capabilities'] ?? []));
    $buttons = array_merge($buttons, (array)($GLOBALS['af_ae_external_buttons'] ?? []));
    foreach ($buttons as $cmd => &$b) {
        if ((!empty($b['opentag']) || !empty($b['closetag'])) && empty($b['handler'])) unset($b['capability']);
        if (isset($b['runtime']) && is_array($b['runtime'])) {
            $id = !empty($b['capability']) ? (string)$b['capability'] : ('custom:' . $cmd);
            $capabilities[$id] = $b['runtime']; $b['capability'] = $id;
        }
        if (in_array($cmd, ['af_togglemode', 'source'], true)) $b['capability'] = 'wysiwyg';
        $iconSvg = trim((string)($b['iconSvg'] ?? ''));
        if ($iconSvg !== '') {
            $b['iconClass'] = '';
            $b['iconGlyph'] = '';
        } else {
            $b['iconClass'] = trim((string)($b['iconClass'] ?? '')) ?: af_advancededitor_shell_fa_icon((string)$cmd);
        }
        $icon = (string)($b['icon'] ?? '');
        if ($icon === '') {
            // Command aliases share the existing vector controls.
            $iconNames = ['af_togglemode' => 'source', 'spoiler' => 'spoilerbb', 'af_kb_insert' => 'book'];
            $b['icon'] = af_advancededitor_shell_icon_url(($iconNames[$cmd] ?? $cmd) . '.svg');
        } else {
            // Retain legacy packs with assets/img/img while resolving icons independently of JS.
            $prefix = af_advancededitor_addon_file_url('assets/img/');
            if (str_starts_with($icon, $prefix)) {
                $name = substr($icon, strlen($prefix));
                $resolvedIcon = af_advancededitor_shell_icon_url($name);
                if ($resolvedIcon !== '') $b['icon'] = $resolvedIcon;
            }
        }
    }
    unset($b);

    // Keep one canonical KB definition even when ACP/custom metadata exposes a
    // second legacy command with another cmd but the same KB capability/title.
    if (isset($buttons['af_kb_insert'])) {
        foreach (array_keys($buttons) as $buttonCmd) {
            if ($buttonCmd === 'af_kb_insert') continue;
            if (af_advancededitor_shell_command_key((string)$buttonCmd, (array)$buttons[$buttonCmd]) === 'af_kb_insert') {
                unset($buttons[$buttonCmd]);
            }
        }
    }

    return ['buttons' => array_values($buttons), 'capabilities' => $capabilities];
}

function af_advancededitor_shell_toolbar(array $buttons, ?array $layout, array $help = []): string
{
    $map = array_column($buttons, null, 'cmd');
    $layoutWasDefault = !isset($layout['sections']) || !is_array($layout['sections']);
    if (!isset($layout['sections']) || !is_array($layout['sections'])) {
        $layout = ['sections' => [
            ['type' => 'group', 'items' => ['bold','italic','underline','strike','subscript','superscript','|','font','size','color','removeformat','|','undo','redo','pastetext','horizontalrule','|','left','center','right','justify','|','bulletlist','orderedlist','|','quote','code','|','link','unlink','email','image','youtube','emoticon','af_stikers','|','maximize']],
            ['type' => 'group', 'items' => []],
        ]];
    }
    // The no-configuration fallback must include installed pack buttons too.
    // Otherwise advanced features vanish entirely when there is no saved ACP
    // toolbar layout (including when ATF changes the surrounding templates).
    if ($layoutWasDefault ?? false) {
        $visible = [];
        foreach ($layout['sections'] as $section) {
            foreach ((array)($section['items'] ?? []) as $cmd) $visible[$cmd] = true;
        }
        $extra = [];
        foreach ($buttons as $definition) {
            $cmd = (string)($definition['cmd'] ?? '');
            if ($cmd !== '' && !isset($visible[$cmd]) &&
                (!empty($definition['packId']) || str_starts_with($cmd, 'af_'))) {
                $extra[] = $cmd;
            }
        }
        if ($extra) $layout['sections'][] = ['type' => 'group', 'items' => $extra];
    }
    // Help is an independent edge control; never place it in a BBCode group.
    $hasCanonicalKb = isset($map['af_kb_insert']);
    foreach ($layout['sections'] as &$section) {
        $normalized = [];
        foreach ((array)($section['items'] ?? []) as $cmd) {
            if ($cmd === 'af_formathelp') continue;
            $key = af_advancededitor_shell_command_key((string)$cmd, $map[$cmd] ?? []);
            if ($hasCanonicalKb && $key === 'af_kb_insert') $cmd = 'af_kb_insert';
            $normalized[] = $cmd;
        }
        $section['items'] = array_values($normalized);
    }
    unset($section);
    $escape = static fn($s) => htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $button = static function ($cmd, $b, bool $menuItem = false) use ($escape): string {
        $icon = trim((string)($b['icon'] ?? ''));
        $iconSvg = trim((string)($b['iconSvg'] ?? ''));
        $fa = trim((string)($b['iconClass'] ?? af_advancededitor_shell_fa_icon((string)$cmd)));
        $glyph = (string)($b['iconGlyph'] ?? '');
        if (str_starts_with($icon, '<svg') && str_contains($icon, '</svg>')) $icon = 'data:image/svg+xml,' . rawurlencode($icon);
        if ($menuItem && str_starts_with($iconSvg, '<svg') && str_contains($iconSvg, '</svg>')) {
            $visual = $iconSvg;
        } elseif ($menuItem && $fa !== '') {
            $visual = '<i class="' . $escape($fa) . ' af-ae-fa-glyph" aria-hidden="true">' . $escape($glyph) . '</i>';
        } else {
            $visual = $icon !== '' ? '<img src="' . $escape($icon) . '" alt="" width="16" height="16" />'
                : ($fa !== '' ? '<i class="' . $escape($fa) . '" aria-hidden="true"></i>' : $escape($b['label'] ?? $b['name'] ?? $cmd));
            if ($icon !== '' && (str_contains(strtolower($icon), '.svg') || str_starts_with($icon, 'data:image/svg'))) {
                $cssUrl = str_replace(['"', "\n", "\r"], ['%22', '', ''], $icon);
                $visual = '<span class="af-ae-shell-icon" style="--af-ae-icon-url:url(&quot;' . $escape($cssUrl) . '&quot;)"></span>';
            }
        }
        $label = $menuItem ? '<span class="af-ae-shell-menu-label">' . $escape($b['title'] ?? $cmd) . '</span>' : '';
        $owned = $menuItem ? ' data-af-shell-menu-item="1"' : '';
        return '<a href="#" role="button" class="sceditor-button sceditor-button-' . $escape($cmd) . '" data-af-command="' . $escape($cmd) . '"' . $owned . ' title="' . $escape($b['title'] ?? $cmd) . '" aria-label="' . $escape($b['title'] ?? $cmd) . '"><div>' . $visual . '</div>' . $label . '</a>';
    };
    $html = '<div class="sceditor-toolbar" role="toolbar" aria-label="AdvancedEditor">';
    $n = 0;
    $renderedCommands = [];
    foreach ($layout['sections'] as $section) {
        $items = [];
        foreach ((array)($section['items'] ?? []) as $cmd) {
            if ($cmd === '|') { $items[] = $cmd; continue; }
            if (!isset($map[$cmd]) || $cmd === 'af_formathelp') continue;
            $commandKey = af_advancededitor_shell_command_key((string)$cmd, $map[$cmd]);
            if (isset($renderedCommands[$commandKey])) continue;
            $renderedCommands[$commandKey] = true;
            $items[] = $cmd;
        }
        if (($section['type'] ?? 'group') === 'dropdown') {
            $cmd = 'af_menu_dropdown' . ++$n;
            $title = (string)($section['title'] ?? 'Доп. меню');
            $html .= '<div class="sceditor-group">' . $button($cmd, ['title' => strip_tags($title), 'label' => $title, 'icon' => (str_starts_with($title, '<svg') || preg_match('~^(?:https?:)?//|^/~', $title)) ? $title : af_advancededitor_shell_icon_url('starmenu.svg')]) . '<div class="af-ae-shell-menu" data-af-menu="'.$escape($cmd).'" hidden>';
            foreach ($items as $item) if ($item !== '|') $html .= $button($item, $map[$item], true);
            $html .= '</div></div>';
            continue;
        }
        if (!$items) continue;
        $html .= '<div class="sceditor-group">';
        foreach ($items as $cmd) {
            $html .= $cmd === '|' ? '</div><div class="sceditor-group">' : $button($cmd, $map[$cmd]);
        }
        $html .= '</div>';
    }
    // External definitions belong to the compiled registry. Append only
    // commands absent from the configured layout; never duplicate KB buttons.
    foreach ((array)($GLOBALS['af_ae_external_buttons'] ?? []) as $externalKey => $external) {
        $cmd = (string)($external['cmd'] ?? $externalKey);
        if ($cmd === '' || !isset($map[$cmd])) continue;
        $commandKey = af_advancededitor_shell_command_key($cmd, $map[$cmd]);
        if (isset($renderedCommands[$commandKey])) continue;
        $renderedCommands[$commandKey] = true;
        $html .= '<div class="sceditor-group">' . $button($cmd, $map[$cmd]) . '</div>';
    }
    $html .= '<!--af-ae-toolbar-end--></div>';
    return $html;
}

/** Attach late addon metadata once to each compiled toolbar. */
function af_advancededitor_shell_attach_button(string $page, array $button): string
{
    $registry = af_advancededitor_shell_registry([$button], [], ['packs' => []]);
    $toolbar = af_advancededitor_shell_toolbar($registry['buttons'], ['sections' => [
        ['type' => 'group', 'items' => [$button['cmd']]],
    ]]);
    // Only this command's first group; other external metadata may also exist.
    preg_match('~<div class="sceditor-group">.*?</a></div>~s', $toolbar, $rendered);
    $group = $rendered[0] ?? '';
    return preg_replace_callback('~(<div\b[^>]*class="sceditor-toolbar"[^>]*>)(.*?)(<!--af-ae-toolbar-end-->)~s',
        static function ($match) use ($button, $group) {
            $key = af_advancededitor_shell_command_key((string)($button['cmd'] ?? ''), $button);
            $present = false;
            if ($key === 'af_kb_insert') {
                $present = (bool)preg_match('~data-af-command="(?:kb|kb_insert|af_kb|af_kb_insert|knowledgebase|knowledgebase_insert)"~i', $match[2]);
            } else {
                $command = 'data-af-command="' . htmlspecialchars((string)$button['cmd'], ENT_QUOTES, 'UTF-8') . '"';
                $present = str_contains($match[2], $command);
            }
            return $match[1] . $match[2] . ($present ? '' : $group) . $match[3];
        }, $page) ?? $page;
}

function af_advancededitor_render_shells(string $page, string $toolbar, bool $counter = true): string
{
    if (str_contains($page, 'data-af-editor-shell="1"')) return $page;
    return preg_replace_callback('~<textarea\b([^>]*)>(.*?)</textarea>~is', static function ($m) use ($toolbar, $counter) {
        if (!preg_match('~\bname\s*=\s*(["\'])message\1|\bclass\s*=\s*(["\'])[^"\']*\b(?:af-atf-bbcode-editor|af-kb-editor)\b~i', $m[1])
            || preg_match('~data-af-ae-skip\s*=\s*(["\'])1\1~i', $m[1])) return $m[0];
        return '<div class="af-ae-shell" data-af-editor-shell="1">' . $toolbar . $m[0] . ($counter ? af_advancededitor_shell_counter_html() : '') . '</div>';
    }, $page) ?? $page;
}

function af_advancededitor_shell_counter_html(): string
{
    return '<div class="af-ccp-wrap"><div class="af-ccp-bar"><span class="af-ccp-label"></span><span class="af-ccp-value">0</span><span class="af-ccp-muted"><sup>SYM</sup></span></div><div class="af-ccp-preview" hidden><div class="af-ccp-preview-title"><span class="af-ccp-preview-title-text">Предпросмотр</span><button type="button" class="af-ccp-preview-close">Закрыть превью</button></div><div class="af-ccp-preview-body"></div></div></div>';
}
