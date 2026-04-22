<?php

return [
    'id'          => 'characterworkflow',
    'name'        => 'CharacterWorkflow',
    'description' => 'Оркестратор модерационного workflow анкеты персонажа.',
    'version'     => '0.1.0',
    'author'      => 'CaptainPaws',
    'website'     => 'https://github.com/CaptainPaws',
    'bootstrap'   => 'characterworkflow.php',
    'lang' => [
        'russian' => [
            'front' => [
                'af_characterworkflow_name' => 'CharacterWorkflow',
                'af_characterworkflow_description' => 'Оркестрация workflow модерации анкеты персонажа.',
            ],
            'admin' => [
                'af_characterworkflow_group' => 'AF: CharacterWorkflow',
                'af_characterworkflow_group_desc' => 'Настройки оркестратора workflow анкеты.',
                'af_characterworkflow_enabled' => 'Включить CharacterWorkflow',
                'af_characterworkflow_enabled_desc' => 'Включает таблицу состояний и API оркестрации модерации анкеты.',
            ],
        ],
        'english' => [
            'front' => [
                'af_characterworkflow_name' => 'CharacterWorkflow',
                'af_characterworkflow_description' => 'Orchestrates character application moderation workflow.',
            ],
            'admin' => [
                'af_characterworkflow_group' => 'AF: CharacterWorkflow',
                'af_characterworkflow_group_desc' => 'Character application workflow orchestrator settings.',
                'af_characterworkflow_enabled' => 'Enable CharacterWorkflow',
                'af_characterworkflow_enabled_desc' => 'Enables workflow state table and moderation orchestration API.',
            ],
        ],
    ],
];
