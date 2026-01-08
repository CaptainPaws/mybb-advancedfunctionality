<?php

return [
    'cmd'     => 'af_table',
    'name'    => 'table',
    'title'   => 'Таблица',
    'icon'    => 'img/aqr-table.svg', // относительный путь внутри assets/ (нормализуем)
    'handler' => 'table',

    // для table handler они не обязательны, но пусть будут явно:
    'opentag'  => '',
    'closetag' => '',
];
