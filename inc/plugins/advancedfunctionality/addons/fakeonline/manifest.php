<?php
/**
 * AF Addon Manifest: Fake Online
 */

return [
    'id'       => 'fakeonline',
    'name'     => 'Фейковый онлайн',
    'version'  => '1.0.0',
    'author'   => 'CaptainPaws',
    'website'  => 'https://github.com/CaptainPaws',
    'bootstrap'=> 'fakeonline.php',

    // Админка аддона (AF router)
    'admin' => [
        'slug'       => 'fakeonline',
        'controller' => 'admin.php',
    ],

    // Языковые ключи для автогенерации ядром AF (RU/EN front/admin)
    'lang' => [
        'front' => [
            'af_fakeonline_name' => 'Фейковый онлайн',
            'af_fakeonline_description' => 'Имитирует активность выбранных профилей в списке онлайн.',
        ],
        'admin' => [
            'af_fakeonline_group' => 'Фейковый онлайн',
            'af_fakeonline_group_desc' => 'Настройки имитации онлайна и поведения фейковых профилей.',

            'af_fakeonline_enabled' => 'Включить аддон',
            'af_fakeonline_enabled_desc' => 'Если выключено — задача не обновляет фейковые сессии.',

            'af_fakeonline_profiles' => 'Профили для фейкового онлайна',
            'af_fakeonline_profiles_desc' => 'UID или usernames. Разделители: запятая, пробел, новая строка. Пример: 12, 15, Admin, NPC_One',

            'af_fakeonline_min_interval' => 'Минимальный интервал действий (сек)',
            'af_fakeonline_min_interval_desc' => 'Минимальная пауза между “переходами” по страницам, когда профиль онлайн.',

            'af_fakeonline_max_interval' => 'Максимальный интервал действий (сек)',
            'af_fakeonline_max_interval_desc' => 'Максимальная пауза между “переходами” по страницам, когда профиль онлайн.',

            'af_fakeonline_session_minutes' => 'Длительность “онлайн-сессии” (мин)',
            'af_fakeonline_session_minutes_desc' => 'Сколько минут профиль держится онлайн после появления, прежде чем “уйдёт”.',

            'af_fakeonline_spawn_chance' => 'Шанс появления онлайн (%)',
            'af_fakeonline_spawn_chance_desc' => 'Когда профиль оффлайн и наступило время — с таким шансом он “появится” онлайн.',

            'af_fakeonline_max_online' => 'Макс. фейковых онлайн одновременно',
            'af_fakeonline_max_online_desc' => 'Ограничивает количество одновременно активных фейковых профилей.',

            'af_fakeonline_skip_real' => 'Не трогать, если профиль реально онлайн',
            'af_fakeonline_skip_real_desc' => 'Если у аккаунта есть настоящая активная сессия (не AF FakeOnline), задача не создаёт/не обновляет фейковую.',

            'af_fakeonline_debug' => 'Debug-логирование задачи',
            'af_fakeonline_debug_desc' => 'Если включено — задача пишет расширенный лог в task log (если logging включён у самой задачи).',
        ],
    ],
];
