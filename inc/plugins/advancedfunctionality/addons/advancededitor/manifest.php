<?php
/**
 * AF Addon Manifest: AdvancedEditor
 */
return [
    'id'       => 'advancededitor',
    'name'     => 'Advanced Editor',
    'version'  => '1.0.0',
    'author'   => 'CaptainPaws',
    'bootstrap'=> 'advancededitor.php',

    'frontend' => [
        'mode' => 'contextual',
        'routes' => [
            // KB owns the HTML and publishes the canonical editor marker late,
            // in the rendered response.  Declare both its canonical entry
            // point and retained misc.php aliases so bootstrap-time permission
            // checks cannot discard the editor before response facts exist.
            ['script' => 'kb.php', 'action' => 'kb_edit'],
            ['script' => 'kb.php', 'action' => 'kb_type_edit'],
            ['script' => 'misc.php', 'action' => 'kb_edit'],
            ['script' => 'misc.php', 'action' => 'kb_type_edit'],
        ],
        'response_rules' => [
            ['has_supported_editor' => true],
            // AdvancedThreadFields owns this response context and marks only
            // its BBCode-enabled textarea controls with this fact.
            ['has_atf_editor' => true],
            ['has_post_content' => true],
        ],
        'directory_fallback' => false,
    ],

    // AF core сам синхронизирует языки по этим ключам (как у вас принято)
    'lang' => [
        'front' => [
            'af_advancededitor_name'        => 'Advanced Editor',
            'af_advancededitor_description' => 'Единый расширенный редактор (SCEditor) + кастомный тулбар + BB-паки.',
        ],
        'admin' => [
            'af_advancededitor_group'       => 'Advanced Editor',
            'af_advancededitor_group_desc'  => 'Настройка расширенного редактора, кнопок и тулбара.',
            'af_advancededitor_enabled'     => 'Включить Advanced Editor',
            'af_advancededitor_enabled_desc'=> 'Если выключено — аддон не вмешивается в редактор.',
            'af_advancededitor_wysiwyg_mode'         => 'WYSIWYG Mode / Режим визуального редактора',
            'af_advancededitor_wysiwyg_mode_full'    => 'Full WYSIWYG / Полный визуальный режим (рендер всех BBCode)',
            'af_advancededitor_wysiwyg_mode_partial' => 'Partial WYSIWYG / Частичный режим (сложные BBCode остаются текстом)',
            'af_advancededitor_help_tab' => 'Подсказка по форматированию',
            'af_advancededitor_help_title' => 'Заголовок подсказки',
            'af_advancededitor_help_content' => 'Контент подсказки',
        ],
    ],

    'admin' => [
        'slug'       => 'advancededitor',
        'controller' => 'admin.php', // AF router загрузит и вызовет AF_Admin_AdvancedEditor::dispatch()
    ],
    // Explicit exclusions keep feature CSS out of the global theme bundle.
    'theme_stylesheets' => [
        ['id' => 'advancededitor_shell', 'file' => 'assets/advancededitor_shell.css', 'stylesheet_name' => 'af_advancededitor_shell.css', 'attach' => [['file' => 'global']], 'enabled_setting' => 'af_advancededitor_enabled'],
        ['id' => 'advancededitor_main', 'file' => 'assets/advancededitor.css', 'stylesheet_name' => 'af_advancededitor.css', 'exclude_autodiscovery' => true, 'disable_theme_integration' => true, 'attach' => [['file' => 'global']], 'enabled_setting' => 'af_advancededitor_enabled'],
        ['id' => 'advancededitor_bbcode_abbr', 'file' => 'assets/bbcodes/bbcodes/abbr/abbr.css', 'stylesheet_name' => 'af_advancededitor_abbr.css', 'exclude_autodiscovery' => true, 'disable_theme_integration' => true, 'attach' => [['file' => 'global']], 'enabled_setting' => 'af_advancededitor_enabled'],
        ['id' => 'advancededitor_bbcode_accordion', 'file' => 'assets/bbcodes/bbcodes/accordion/accordion.css', 'stylesheet_name' => 'af_advancededitor_accordion.css', 'exclude_autodiscovery' => true, 'disable_theme_integration' => true, 'attach' => [['file' => 'global']], 'enabled_setting' => 'af_advancededitor_enabled'],
        ['id' => 'advancededitor_bbcode_align', 'file' => 'assets/bbcodes/bbcodes/align/align.css', 'stylesheet_name' => 'af_advancededitor_align.css', 'exclude_autodiscovery' => true, 'disable_theme_integration' => true, 'attach' => [['file' => 'global']], 'enabled_setting' => 'af_advancededitor_enabled'],
        ['id' => 'advancededitor_bbcode_anchors', 'file' => 'assets/bbcodes/bbcodes/anchors/anchors.css', 'stylesheet_name' => 'af_advancededitor_anchors.css', 'exclude_autodiscovery' => true, 'disable_theme_integration' => true, 'attach' => [['file' => 'global']], 'enabled_setting' => 'af_advancededitor_enabled'],
        ['id' => 'advancededitor_bbcode_charcountandprew', 'file' => 'assets/bbcodes/bbcodes/charcountandprew/charcountandprew.css', 'stylesheet_name' => 'af_advancededitor_charcountandprew.css', 'exclude_autodiscovery' => true, 'disable_theme_integration' => true, 'attach' => [['file' => 'global']], 'enabled_setting' => 'af_advancededitor_enabled'],
        ['id' => 'advancededitor_bbcode_copycode', 'file' => 'assets/bbcodes/bbcodes/copycode/copycode.css', 'stylesheet_name' => 'af_advancededitor_copycode.css', 'exclude_autodiscovery' => true, 'disable_theme_integration' => true, 'attach' => [['file' => 'global']], 'enabled_setting' => 'af_advancededitor_enabled'],
        ['id' => 'advancededitor_bbcode_drafts', 'file' => 'assets/bbcodes/bbcodes/drafts/drafts.css', 'stylesheet_name' => 'af_advancededitor_drafts.css', 'exclude_autodiscovery' => true, 'disable_theme_integration' => true, 'attach' => [['file' => 'global']], 'enabled_setting' => 'af_advancededitor_enabled'],
        ['id' => 'advancededitor_bbcode_embedvideos', 'file' => 'assets/bbcodes/bbcodes/embedvideos/embedvideos.css', 'stylesheet_name' => 'af_advancededitor_embedvideos.css', 'exclude_autodiscovery' => true, 'disable_theme_integration' => true, 'attach' => [['file' => 'global']], 'enabled_setting' => 'af_advancededitor_enabled'],
        ['id' => 'advancededitor_bbcode_floatbb', 'file' => 'assets/bbcodes/bbcodes/floatbb/floatbb.css', 'stylesheet_name' => 'af_advancededitor_floatbb.css', 'exclude_autodiscovery' => true, 'disable_theme_integration' => true, 'attach' => [['file' => 'global']], 'enabled_setting' => 'af_advancededitor_enabled'],
        ['id' => 'advancededitor_bbcode_fontfamily', 'file' => 'assets/bbcodes/bbcodes/fontfamily/fontfamily.css', 'stylesheet_name' => 'af_advancededitor_fontfamily.css', 'exclude_autodiscovery' => true, 'disable_theme_integration' => true, 'attach' => [['file' => 'global']], 'enabled_setting' => 'af_advancededitor_enabled'],
        ['id' => 'advancededitor_bbcode_fontsize', 'file' => 'assets/bbcodes/bbcodes/fontsize/fontsize.css', 'stylesheet_name' => 'af_advancededitor_fontsize.css', 'exclude_autodiscovery' => true, 'disable_theme_integration' => true, 'attach' => [['file' => 'global']], 'enabled_setting' => 'af_advancededitor_enabled'],
        ['id' => 'advancededitor_bbcode_htmlbb', 'file' => 'assets/bbcodes/bbcodes/htmlbb/htmlbb.css', 'stylesheet_name' => 'af_advancededitor_htmlbb.css', 'exclude_autodiscovery' => true, 'disable_theme_integration' => true, 'attach' => [['file' => 'global']], 'enabled_setting' => 'af_advancededitor_enabled'],
        ['id' => 'advancededitor_bbcode_indent', 'file' => 'assets/bbcodes/bbcodes/indent/indent.css', 'stylesheet_name' => 'af_advancededitor_indent.css', 'exclude_autodiscovery' => true, 'disable_theme_integration' => true, 'attach' => [['file' => 'global']], 'enabled_setting' => 'af_advancededitor_enabled'],
        ['id' => 'advancededitor_bbcode_jscolorpiker', 'file' => 'assets/bbcodes/bbcodes/jscolorpiker/jscolorpiker.css', 'stylesheet_name' => 'af_advancededitor_jscolorpiker.css', 'exclude_autodiscovery' => true, 'disable_theme_integration' => true, 'attach' => [['file' => 'global']], 'enabled_setting' => 'af_advancededitor_enabled'],
        ['id' => 'advancededitor_bbcode_lists', 'file' => 'assets/bbcodes/bbcodes/lists/lists.css', 'stylesheet_name' => 'af_advancededitor_lists.css', 'exclude_autodiscovery' => true, 'disable_theme_integration' => true, 'attach' => [['file' => 'global']], 'enabled_setting' => 'af_advancededitor_enabled'],
        ['id' => 'advancededitor_bbcode_lockcontent', 'file' => 'assets/bbcodes/bbcodes/lockcontent/lockcontent.css', 'stylesheet_name' => 'af_advancededitor_lockcontent.css', 'exclude_autodiscovery' => true, 'disable_theme_integration' => true, 'attach' => [['file' => 'global']], 'enabled_setting' => 'af_advancededitor_enabled'],
        ['id' => 'advancededitor_bbcode_mark', 'file' => 'assets/bbcodes/bbcodes/mark/mark.css', 'stylesheet_name' => 'af_advancededitor_mark.css', 'exclude_autodiscovery' => true, 'disable_theme_integration' => true, 'attach' => [['file' => 'global']], 'enabled_setting' => 'af_advancededitor_enabled'],
        ['id' => 'advancededitor_bbcode_resizeimg', 'file' => 'assets/bbcodes/bbcodes/resizeimg/resizeimg.css', 'stylesheet_name' => 'af_advancededitor_resizeimg.css', 'exclude_autodiscovery' => true, 'disable_theme_integration' => true, 'attach' => [['file' => 'global']], 'enabled_setting' => 'af_advancededitor_enabled'],
        ['id' => 'advancededitor_bbcode_spoiler', 'file' => 'assets/bbcodes/bbcodes/spoiler/spoiler.css', 'stylesheet_name' => 'af_advancededitor_spoiler.css', 'exclude_autodiscovery' => true, 'disable_theme_integration' => true, 'attach' => [['file' => 'global']], 'enabled_setting' => 'af_advancededitor_enabled'],
        ['id' => 'advancededitor_bbcode_tables', 'file' => 'assets/bbcodes/bbcodes/tables/tables.css', 'stylesheet_name' => 'af_advancededitor_tables.css', 'exclude_autodiscovery' => true, 'disable_theme_integration' => true, 'attach' => [['file' => 'global']], 'enabled_setting' => 'af_advancededitor_enabled'],
        ['id' => 'advancededitor_bbcode_tabs', 'file' => 'assets/bbcodes/bbcodes/tabs/tabs.css', 'stylesheet_name' => 'af_advancededitor_tabs.css', 'exclude_autodiscovery' => true, 'disable_theme_integration' => true, 'attach' => [['file' => 'global']], 'enabled_setting' => 'af_advancededitor_enabled'],
        ['id' => 'advancededitor_bbcode_tquote', 'file' => 'assets/bbcodes/bbcodes/tquote/tquote.css', 'stylesheet_name' => 'af_advancededitor_tquote.css', 'exclude_autodiscovery' => true, 'disable_theme_integration' => true, 'attach' => [['file' => 'global']], 'enabled_setting' => 'af_advancededitor_enabled'],
        ['id' => 'advancededitor_stikers', 'file' => 'assets/bbcodes/stikers/stikers.css', 'stylesheet_name' => 'af_advancededitor_stikers.css', 'exclude_autodiscovery' => true, 'disable_theme_integration' => true, 'attach' => [['file' => 'global']], 'enabled_setting' => 'af_advancededitor_enabled'],
    ],
];
