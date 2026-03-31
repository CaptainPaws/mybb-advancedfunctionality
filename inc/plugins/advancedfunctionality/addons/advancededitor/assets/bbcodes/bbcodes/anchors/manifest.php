<?php

return [
    'id'    => 'anchors',
    'title' => 'Внутренние якоря',
    'tags'  => ['anchor', 'anchorlink'],

    'buttons' => [
        [
            'cmd'      => 'af_anchor',
            'name'     => 'anchor',
            'title'    => 'Якорь (точка)',
            'hint'     => 'Вставить [anchor id="section_1"][/anchor]',
            'handler'  => 'anchor',
            'opentag'  => '[anchor id="section_1"]',
            'closetag' => '[/anchor]',
        ],
        [
            'cmd'      => 'af_anchorlink',
            'name'     => 'anchorlink',
            'title'    => 'Ссылка на якорь',
            'hint'     => 'Вставить [anchorlink target="section_1"]Текст[/anchorlink]',
            'handler'  => 'anchorlink',
            'opentag'  => '[anchorlink target="section_1"]',
            'closetag' => '[/anchorlink]',
        ],
    ],

    'assets' => [
        'css' => [
            'bbcodes/anchors/anchors.css',
        ],
        'js' => [
            'bbcodes/anchors/anchors.js',
        ],
    ],

    'parser' => 'anchors.php',
];
