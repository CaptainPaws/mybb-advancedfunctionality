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
    'theme_stylesheets' => [
        ['id' => 'advancededitor_main', 'file' => 'assets/advancededitor.css', 'stylesheet_name' => 'af_advancededitor.css', 'attach' => [['file' => 'global']], 'enabled_setting' => 'af_advancededitor_enabled'],
        ['id' => 'advancededitor_bbcode_abbr', 'file' => 'assets/bbcodes/bbcodes/abbr/abbr.css', 'stylesheet_name' => 'af_advancededitor_abbr.css', 'attach' => [['file' => 'global']], 'enabled_setting' => 'af_advancededitor_enabled'],
        ['id' => 'advancededitor_bbcode_accordion', 'file' => 'assets/bbcodes/bbcodes/accordion/accordion.css', 'stylesheet_name' => 'af_advancededitor_accordion.css', 'attach' => [['file' => 'global']], 'enabled_setting' => 'af_advancededitor_enabled'],
        ['id' => 'advancededitor_bbcode_align', 'file' => 'assets/bbcodes/bbcodes/align/align.css', 'stylesheet_name' => 'af_advancededitor_align.css', 'attach' => [['file' => 'global']], 'enabled_setting' => 'af_advancededitor_enabled'],
        ['id' => 'advancededitor_bbcode_anchors', 'file' => 'assets/bbcodes/bbcodes/anchors/anchors.css', 'stylesheet_name' => 'af_advancededitor_anchors.css', 'attach' => [['file' => 'global']], 'enabled_setting' => 'af_advancededitor_enabled'],
        ['id' => 'advancededitor_bbcode_charcountandprew', 'file' => 'assets/bbcodes/bbcodes/charcountandprew/charcountandprew.css', 'stylesheet_name' => 'af_advancededitor_charcountandprew.css', 'attach' => [['file' => 'global']], 'enabled_setting' => 'af_advancededitor_enabled'],
        ['id' => 'advancededitor_bbcode_copycode', 'file' => 'assets/bbcodes/bbcodes/copycode/copycode.css', 'stylesheet_name' => 'af_advancededitor_copycode.css', 'attach' => [['file' => 'global']], 'enabled_setting' => 'af_advancededitor_enabled'],
        ['id' => 'advancededitor_bbcode_drafts', 'file' => 'assets/bbcodes/bbcodes/drafts/drafts.css', 'stylesheet_name' => 'af_advancededitor_drafts.css', 'attach' => [['file' => 'global']], 'enabled_setting' => 'af_advancededitor_enabled'],
        ['id' => 'advancededitor_bbcode_embedvideos', 'file' => 'assets/bbcodes/bbcodes/embedvideos/embedvideos.css', 'stylesheet_name' => 'af_advancededitor_embedvideos.css', 'attach' => [['file' => 'global']], 'enabled_setting' => 'af_advancededitor_enabled'],
        ['id' => 'advancededitor_bbcode_floatbb', 'file' => 'assets/bbcodes/bbcodes/floatbb/floatbb.css', 'stylesheet_name' => 'af_advancededitor_floatbb.css', 'attach' => [['file' => 'global']], 'enabled_setting' => 'af_advancededitor_enabled'],
        ['id' => 'advancededitor_bbcode_fontfamily', 'file' => 'assets/bbcodes/bbcodes/fontfamily/fontfamily.css', 'stylesheet_name' => 'af_advancededitor_fontfamily.css', 'attach' => [['file' => 'global']], 'enabled_setting' => 'af_advancededitor_enabled'],
        ['id' => 'advancededitor_bbcode_fontsize', 'file' => 'assets/bbcodes/bbcodes/fontsize/fontsize.css', 'stylesheet_name' => 'af_advancededitor_fontsize.css', 'attach' => [['file' => 'global']], 'enabled_setting' => 'af_advancededitor_enabled'],
        ['id' => 'advancededitor_bbcode_htmlbb', 'file' => 'assets/bbcodes/bbcodes/htmlbb/htmlbb.css', 'stylesheet_name' => 'af_advancededitor_htmlbb.css', 'attach' => [['file' => 'global']], 'enabled_setting' => 'af_advancededitor_enabled'],
        ['id' => 'advancededitor_bbcode_indent', 'file' => 'assets/bbcodes/bbcodes/indent/indent.css', 'stylesheet_name' => 'af_advancededitor_indent.css', 'attach' => [['file' => 'global']], 'enabled_setting' => 'af_advancededitor_enabled'],
        ['id' => 'advancededitor_bbcode_jscolorpiker', 'file' => 'assets/bbcodes/bbcodes/jscolorpiker/jscolorpiker.css', 'stylesheet_name' => 'af_advancededitor_jscolorpiker.css', 'attach' => [['file' => 'global']], 'enabled_setting' => 'af_advancededitor_enabled'],
        ['id' => 'advancededitor_bbcode_lists', 'file' => 'assets/bbcodes/bbcodes/lists/lists.css', 'stylesheet_name' => 'af_advancededitor_lists.css', 'attach' => [['file' => 'global']], 'enabled_setting' => 'af_advancededitor_enabled'],
        ['id' => 'advancededitor_bbcode_lockcontent', 'file' => 'assets/bbcodes/bbcodes/lockcontent/lockcontent.css', 'stylesheet_name' => 'af_advancededitor_lockcontent.css', 'attach' => [['file' => 'global']], 'enabled_setting' => 'af_advancededitor_enabled'],
        ['id' => 'advancededitor_bbcode_mark', 'file' => 'assets/bbcodes/bbcodes/mark/mark.css', 'stylesheet_name' => 'af_advancededitor_mark.css', 'attach' => [['file' => 'global']], 'enabled_setting' => 'af_advancededitor_enabled'],
        ['id' => 'advancededitor_bbcode_resizeimg', 'file' => 'assets/bbcodes/bbcodes/resizeimg/resizeimg.css', 'stylesheet_name' => 'af_advancededitor_resizeimg.css', 'attach' => [['file' => 'global']], 'enabled_setting' => 'af_advancededitor_enabled'],
        ['id' => 'advancededitor_bbcode_spoiler', 'file' => 'assets/bbcodes/bbcodes/spoiler/spoiler.css', 'stylesheet_name' => 'af_advancededitor_spoiler.css', 'attach' => [['file' => 'global']], 'enabled_setting' => 'af_advancededitor_enabled'],
        ['id' => 'advancededitor_bbcode_tables', 'file' => 'assets/bbcodes/bbcodes/tables/tables.css', 'stylesheet_name' => 'af_advancededitor_tables.css', 'attach' => [['file' => 'global']], 'enabled_setting' => 'af_advancededitor_enabled'],
        ['id' => 'advancededitor_bbcode_tabs', 'file' => 'assets/bbcodes/bbcodes/tabs/tabs.css', 'stylesheet_name' => 'af_advancededitor_tabs.css', 'attach' => [['file' => 'global']], 'enabled_setting' => 'af_advancededitor_enabled'],
        ['id' => 'advancededitor_bbcode_tquote', 'file' => 'assets/bbcodes/bbcodes/tquote/tquote.css', 'stylesheet_name' => 'af_advancededitor_tquote.css', 'attach' => [['file' => 'global']], 'enabled_setting' => 'af_advancededitor_enabled'],
        ['id' => 'advancededitor_stikers', 'file' => 'assets/bbcodes/stikers/stikers.css', 'stylesheet_name' => 'af_advancededitor_stikers.css', 'attach' => [['file' => 'global']], 'enabled_setting' => 'af_advancededitor_enabled'],
    ],
];
