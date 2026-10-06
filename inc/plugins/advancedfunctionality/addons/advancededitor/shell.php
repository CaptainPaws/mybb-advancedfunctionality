<?php
/** Metadata compiler boundary. No filesystem or AJAX work is needed on a click. */
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
        $icon = (string)($b['icon'] ?? '');
        if ($icon === '') {
            $rel = 'assets/img/img/' . $cmd . '.svg';
            if (is_file(__DIR__ . '/' . $rel)) $b['icon'] = af_advancededitor_addon_file_url($rel);
        } else {
            // Retain legacy packs with assets/img/img while resolving icons independently of JS.
            $prefix = af_advancededitor_addon_file_url('assets/img/');
            if (str_starts_with($icon, $prefix)) {
                $name = substr($icon, strlen($prefix));
                if (!is_file(__DIR__ . '/assets/img/' . $name) && is_file(__DIR__ . '/assets/img/img/' . $name)) {
                    $b['icon'] = $prefix . 'img/' . $name;
                }
            }
        }
    }
    unset($b);
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
    foreach ($layout['sections'] as &$section) {
        $section['items'] = array_values(array_filter((array)($section['items'] ?? []),
            static fn($cmd) => $cmd !== 'af_formathelp'));
    }
    unset($section);
    $escape = static fn($s) => htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $button = static function ($cmd, $b, bool $menuItem = false) use ($escape): string {
        $icon = trim((string)($b['icon'] ?? ''));
        if (str_starts_with($icon, '<svg') && str_contains($icon, '</svg>')) $icon = 'data:image/svg+xml,' . rawurlencode($icon);
        $visual = $icon !== '' ? '<img src="' . $escape($icon) . '" alt="" width="16" height="16" />' : $escape($b['label'] ?? $b['name'] ?? $cmd);
        if ($icon !== '' && (str_contains(strtolower($icon), '.svg') || str_starts_with($icon, 'data:image/svg'))) {
            $cssUrl = str_replace(['"', "\n", "\r"], ['%22', '', ''], $icon);
            $visual = '<span class="af-ae-shell-icon" style="--af-ae-icon-url:url(&quot;' . $escape($cssUrl) . '&quot;)"></span>';
        }
        $label = $menuItem ? '<span class="af-ae-shell-menu-label">' . $escape($b['title'] ?? $cmd) . '</span>' : '';
        return '<a href="#" role="button" class="sceditor-button sceditor-button-' . $escape($cmd) . '" data-af-command="' . $escape($cmd) . '" title="' . $escape($b['title'] ?? $cmd) . '" aria-label="' . $escape($b['title'] ?? $cmd) . '"><div>' . $visual . '</div>' . $label . '</a>';
    };
    $html = '<div class="sceditor-toolbar" role="toolbar" aria-label="AdvancedEditor">';
    $n = 0;
    $renderedCommands = [];
    foreach ($layout['sections'] as $section) {
        $items = [];
        foreach ((array)($section['items'] ?? []) as $cmd) {
            if ($cmd === '|') { $items[] = $cmd; continue; }
            if (!isset($map[$cmd]) || isset($renderedCommands[$cmd]) || $cmd === 'af_formathelp') continue;
            $renderedCommands[$cmd] = true;
            $items[] = $cmd;
        }
        if (($section['type'] ?? 'group') === 'dropdown') {
            $cmd = 'af_menu_dropdown' . ++$n;
            $title = (string)($section['title'] ?? '★');
            $html .= '<div class="sceditor-group">' . $button($cmd, ['title' => strip_tags($title), 'label' => $title, 'icon' => (str_starts_with($title, '<svg') || preg_match('~^(?:https?:)?//|^/~', $title)) ? $title : '']) . '<div class="af-ae-shell-menu" data-af-menu="'.$escape($cmd).'" hidden>';
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
        if ($cmd === '' || isset($renderedCommands[$cmd]) || !isset($map[$cmd])) continue;
        $renderedCommands[$cmd] = true;
        $html .= '<div class="sceditor-group">' . $button($cmd, $map[$cmd]) . '</div>';
    }
    $html .= '<!--af-ae-toolbar-end--></div>';
    return $html;
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
