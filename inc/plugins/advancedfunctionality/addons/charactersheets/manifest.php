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

    // Если у аддона будет AF-страница в ACP через роутер:
    // (если admin.php реально отсутствует — можешь удалить этот блок целиком)
    'admin' => [
        'slug'       => 'charactersheets',
        'name'       => 'CharacterSheets',
        'controller' => 'admin.php',
    ],

    /**
     * Языковые пакеты генерируются ядром AF по этим ключам.
     * Канон: russian/english + секции front/admin без _ru/_en суффиксов.
     */
    'lang' => [
        'russian' => [
            'front' => [
                'af_charactersheets_name'              => 'CharacterSheets',
                'af_charactersheets_description'       => 'Автопринятие анкет и триггер листов персонажей.',
                'af_charactersheets_accept_button'     => 'Принять анкету',
                'af_charactersheets_accept_done'       => 'Анкета принята: тема закрыта и перенесена.',
                'af_charactersheets_accept_already'    => 'Анкета уже была принята.',
                'af_charactersheets_accept_error'      => 'Не удалось принять анкету. Обратитесь к администратору.',
                'af_charactersheets_accept_no_permission' => 'У вас нет прав для принятия анкеты.',
                'af_charactersheets_accept_invalid_thread' => 'Тема не найдена или недоступна.',
                'af_charactersheets_sheet_button'      => 'Лист персонажа',
                'af_charactersheets_sheet_modal_title' => 'Лист персонажа',
                'af_charactersheets_sheet_modal_close' => 'Закрыть',
            ],
            'admin' => [
                'af_charactersheets_group'                   => 'AF: CharacterSheets',
                'af_charactersheets_group_desc'              => 'Автопринятие анкет, перенос и триггер листов персонажей.',

                'af_charactersheets_enabled'                 => 'Включить CharacterSheets',
                'af_charactersheets_enabled_desc'            => 'Включает кнопку принятия анкеты и обработчик misc.php.',

                'af_charactersheets_accept_groups'           => 'Группы, которым доступно принятие',
                'af_charactersheets_accept_groups_desc'      => 'CSV id групп (usergroup/additionalgroups), которым доступна кнопка принятия анкеты. Пример: 4,3,6',

                'af_charactersheets_pending_forums'          => 'Форумы ожидания анкет',
                'af_charactersheets_pending_forums_desc'     => 'CSV fid форумов, где анкеты считаются ожидающими. Пример: 12,13,14',

                'af_charactersheets_accepted_forum'          => 'Форум принятых анкет',
                'af_charactersheets_accepted_forum_desc'     => 'fid форума, куда переносить принятые анкеты.',

                'af_charactersheets_accept_wrap_htmlbb'      => 'Оборачивать сообщение в [html][/html]',
                'af_charactersheets_accept_wrap_htmlbb_desc' => 'Если включено, текст принятия будет обёрнут в [html]...[/html].',

                'af_charactersheets_accept_close_thread'     => 'Закрывать тему после принятия',
                'af_charactersheets_accept_close_thread_desc'=> 'Если включено, тема будет закрыта.',

                'af_charactersheets_accept_move_thread'      => 'Переносить тему после принятия',
                'af_charactersheets_accept_move_thread_desc' => 'Если включено, тема будет перенесена в форум принятых анкет.',

                'af_charactersheets_sheet_autocreate'        => 'Автосоздание листа персонажа',
                'af_charactersheets_sheet_autocreate_desc'   => 'Если включено, после принятия срабатывает генератор листа персонажа (заглушка).',

                'af_charactersheets_admin_title'             => 'CharacterSheets',
                'af_charactersheets_admin_subtitle'          => 'Настройка текста принятия анкеты.',
                'af_charactersheets_admin_accept_template'   => 'Текст сообщения принятия',
                'af_charactersheets_admin_accept_template_desc' => 'Плейсхолдеры: {mention}, {username}, {uid}, {thread_url}, {profile_url}, {accepted_by}, {sheet_url}, {sheet_slug}.',
                'af_charactersheets_admin_save'              => 'Сохранить',
                'af_charactersheets_admin_saved'             => 'Настройки сохранены.',
            ],
        ],

        'english' => [
            'front' => [
                'af_charactersheets_name'              => 'CharacterSheets',
                'af_charactersheets_description'       => 'Auto-accept applications and trigger character sheets.',
                'af_charactersheets_accept_button'     => 'Accept application',
                'af_charactersheets_accept_done'       => 'Application accepted: thread closed and moved.',
                'af_charactersheets_accept_already'    => 'Application has already been accepted.',
                'af_charactersheets_accept_error'      => 'Failed to accept the application. Contact an administrator.',
                'af_charactersheets_accept_no_permission' => 'You do not have permission to accept this application.',
                'af_charactersheets_accept_invalid_thread' => 'Thread not found or not accessible.',
                'af_charactersheets_sheet_button'      => 'Character sheet',
                'af_charactersheets_sheet_modal_title' => 'Character sheet',
                'af_charactersheets_sheet_modal_close' => 'Close',
            ],
            'admin' => [
                'af_charactersheets_group'                   => 'AF: CharacterSheets',
                'af_charactersheets_group_desc'              => 'CharacterSheets addon settings.',

                'af_charactersheets_enabled'                 => 'Enable CharacterSheets',
                'af_charactersheets_enabled_desc'            => 'Enables the accept button and misc.php handler.',

                'af_charactersheets_accept_groups'           => 'Groups allowed to accept',
                'af_charactersheets_accept_groups_desc'      => 'CSV group ids (usergroup/additionalgroups) allowed to accept. Example: 4,3,6',

                'af_charactersheets_pending_forums'          => 'Pending application forums',
                'af_charactersheets_pending_forums_desc'     => 'CSV forum ids where applications are pending. Example: 12,13,14',

                'af_charactersheets_accepted_forum'          => 'Accepted applications forum',
                'af_charactersheets_accepted_forum_desc'     => 'Forum id to move accepted applications into.',

                'af_charactersheets_accept_wrap_htmlbb'      => 'Wrap acceptance post in [html][/html]',
                'af_charactersheets_accept_wrap_htmlbb_desc' => 'If enabled, the acceptance text is wrapped in [html]...[/html].',

                'af_charactersheets_accept_close_thread'     => 'Close thread after acceptance',
                'af_charactersheets_accept_close_thread_desc'=> 'If enabled, the thread will be closed.',

                'af_charactersheets_accept_move_thread'      => 'Move thread after acceptance',
                'af_charactersheets_accept_move_thread_desc' => 'If enabled, the thread will be moved to the accepted forum.',

                'af_charactersheets_sheet_autocreate'        => 'Auto-create character sheet',
                'af_charactersheets_sheet_autocreate_desc'   => 'If enabled, triggers the character sheet generator (stub).',

                'af_charactersheets_admin_title'             => 'CharacterSheets',
                'af_charactersheets_admin_subtitle'          => 'Acceptance message template settings.',
                'af_charactersheets_admin_accept_template'   => 'Acceptance message',
                'af_charactersheets_admin_accept_template_desc' => 'Placeholders: {mention}, {username}, {uid}, {thread_url}, {profile_url}, {accepted_by}, {sheet_url}, {sheet_slug}.',
                'af_charactersheets_admin_save'              => 'Save',
                'af_charactersheets_admin_saved'             => 'Settings saved.',
            ],
        ],
    ],
];
