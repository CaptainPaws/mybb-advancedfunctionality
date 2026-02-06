<?php
/**
 * AF Addon Manifest: CharacterSheets
 * MyBB 1.8.x, PHP 8.0–8.4
 */

return [
    'id'          => 'charactersheets',
    'name'        => 'CharacterSheets',
    'description' => 'Автопринятие анкет, перенос в архив и триггер листа персонажа.',
    'version'     => '1.0.0',
    'author'      => 'CaptainPaws',
    'website'     => 'https://github.com/CaptainPaws',
    'bootstrap'   => 'charactersheets.php',

    /**
     * Языковые пакеты генерируются ядром AF по этим ключам.
     * Здесь важно иметь и RU и EN тексты (front/admin).
     */
    'lang' => [
        'front' => [
            // RU
            'af_charactersheets_name_ru'        => 'CharacterSheets',
            'af_charactersheets_description_ru' => 'Автопринятие анкет и триггер листов персонажей.',
            'af_charactersheets_accept_button_ru' => 'Принять анкету',
            'af_charactersheets_accept_done_ru'   => 'Анкета принята: тема закрыта и перенесена.',
            'af_charactersheets_accept_already_ru' => 'Анкета уже была принята.',
            'af_charactersheets_accept_error_ru' => 'Не удалось принять анкету. Обратитесь к администратору.',

            // EN
            'af_charactersheets_name_en'        => 'CharacterSheets',
            'af_charactersheets_description_en' => 'Auto-accept applications and trigger character sheets.',
            'af_charactersheets_accept_button_en' => 'Accept application',
            'af_charactersheets_accept_done_en'   => 'Application accepted: thread closed and moved.',
            'af_charactersheets_accept_already_en' => 'Application has already been accepted.',
            'af_charactersheets_accept_error_en' => 'Failed to accept the application. Contact an administrator.',
        ],

        'admin' => [
            // RU
            'af_charactersheets_group_ru'        => 'AF: CharacterSheets',
            'af_charactersheets_group_desc_ru'   => 'Автопринятие анкет и триггер листов персонажей.',

            'af_charactersheets_enabled_ru'      => 'Включить CharacterSheets',
            'af_charactersheets_enabled_desc_ru' => 'Включает кнопку принятия анкеты и обработчик misc.php.',

            'af_charactersheets_accept_groups_ru'      => 'Группы, которым доступно принятие',
            'af_charactersheets_accept_groups_desc_ru' => 'CSV id групп (usergroup/additionalgroups), которым доступна кнопка принятия анкеты. Пример: 4,3,6',

            'af_charactersheets_pending_forums_ru'      => 'Форумы ожидания анкет',
            'af_charactersheets_pending_forums_desc_ru' => 'CSV fid форумов, где анкеты считаются ожидающими. Пример: 12,13,14',

            'af_charactersheets_accepted_forum_ru'      => 'Форум принятых анкет',
            'af_charactersheets_accepted_forum_desc_ru' => 'fid форума, куда переносить принятые анкеты.',

            'af_charactersheets_accept_post_template_ru'      => 'Шаблон сообщения принятия',
            'af_charactersheets_accept_post_template_desc_ru' => "Поддерживает плейсхолдеры: {username}, {uid}, {thread_url}, {profile_url}, {accepted_by}.",

            'af_charactersheets_accept_wrap_htmlbb_ru'      => 'Оборачивать сообщение в [html][/html]',
            'af_charactersheets_accept_wrap_htmlbb_desc_ru' => 'Если включено, текст принятия будет обёрнут в [html]...[/html].',

            'af_charactersheets_accept_close_thread_ru'      => 'Закрывать тему после принятия',
            'af_charactersheets_accept_close_thread_desc_ru' => 'Если включено, тема будет закрыта.',

            'af_charactersheets_accept_move_thread_ru'      => 'Переносить тему после принятия',
            'af_charactersheets_accept_move_thread_desc_ru' => 'Если включено, тема будет перенесена в форум принятых анкет.',

            'af_charactersheets_sheet_autocreate_ru'      => 'Автосоздание листа персонажа',
            'af_charactersheets_sheet_autocreate_desc_ru' => 'Если включено, после принятия срабатывает генератор листа персонажа (заглушка).',

            // EN
            'af_charactersheets_group_en'        => 'AF: CharacterSheets',
            'af_charactersheets_group_desc_en'   => 'Auto-accept applications and trigger character sheets.',

            'af_charactersheets_enabled_en'      => 'Enable CharacterSheets',
            'af_charactersheets_enabled_desc_en' => 'Enables the accept button and misc.php handler.',

            'af_charactersheets_accept_groups_en'      => 'Groups allowed to accept',
            'af_charactersheets_accept_groups_desc_en' => 'CSV group ids (usergroup/additionalgroups) that can accept. Example: 4,3,6',

            'af_charactersheets_pending_forums_en'      => 'Pending application forums',
            'af_charactersheets_pending_forums_desc_en' => 'CSV forum ids where applications are pending. Example: 12,13,14',

            'af_charactersheets_accepted_forum_en'      => 'Accepted applications forum',
            'af_charactersheets_accepted_forum_desc_en' => 'Forum id to move accepted applications into.',

            'af_charactersheets_accept_post_template_en'      => 'Acceptance post template',
            'af_charactersheets_accept_post_template_desc_en' => 'Supports placeholders: {username}, {uid}, {thread_url}, {profile_url}, {accepted_by}.',

            'af_charactersheets_accept_wrap_htmlbb_en'      => 'Wrap acceptance post in [html][/html]',
            'af_charactersheets_accept_wrap_htmlbb_desc_en' => 'If enabled, the acceptance text is wrapped in [html]...[/html].',

            'af_charactersheets_accept_close_thread_en'      => 'Close thread after acceptance',
            'af_charactersheets_accept_close_thread_desc_en' => 'If enabled, the thread will be closed.',

            'af_charactersheets_accept_move_thread_en'      => 'Move thread after acceptance',
            'af_charactersheets_accept_move_thread_desc_en' => 'If enabled, the thread will be moved to the accepted forum.',

            'af_charactersheets_sheet_autocreate_en'      => 'Auto-create character sheet',
            'af_charactersheets_sheet_autocreate_desc_en' => 'If enabled, triggers the character sheet generator (stub).',
        ],
    ],
];
