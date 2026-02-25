<?php
/**
 * AF Addon Manifest: Balance
 * MyBB 1.8.x, PHP 8.0–8.4
 */

return [
    'id'          => 'balance',
    'name'        => 'Баланс',
    'description' => 'EXP + кредиты (игровая валюта): баланс, автоплатежи, ручные начисления и лог транзакций.',
    'version'     => '1.0.0',
    'author'      => 'CaptainPaws',
    'website'     => 'https://github.com/CaptainPaws',
    'bootstrap'   => 'balance.php',

    // интеграция в ACP через AF router
    'admin' => [
        'slug'       => 'balance',
        'name'       => 'Баланс',
        'controller' => 'admin.php',
    ],

    /**
     * Языковые ключи для автогенерации ядром AF.
     * Структура:
     *   front: ключ => текст
     *   admin: ключ => текст
     *
     * Ключи держим в формате af_balance_*
     */
    'lang' => [
        'russian' => [
            'front' => [
                // общие
                'af_balance_name'        => 'Баланс',
                'af_balance_description' => 'EXP и кредиты: начисления, списания, прогресс и история транзакций.',
                'af_balance_exp'         => 'Опыт',
                'af_balance_credits'     => 'Кредиты',
                'af_balance_level'       => 'Уровень',
                'af_balance_progress'    => 'Прогресс',
                'af_balance_to_next'     => 'До следующего уровня',

                // короткие причины (для UI/логов)
                'af_balance_reason_post_chars' => 'Пост (за символы)',
                'af_balance_reason_register'   => 'Регистрация',
                'af_balance_reason_accept'     => 'Принятие анкеты',
                'af_balance_reason_manual'     => 'Ручное начисление',
                'af_balance_reason_quest'      => 'Квест',
                'af_balance_reason_event'      => 'Ивент',
                'af_balance_reason_shop'       => 'Магазин',
            ],
            'admin' => [
                // группа настроек
                'af_balance_group'      => 'Баланс',
                'af_balance_group_desc' => 'Настройки EXP и кредитов, а также лог транзакций.',

                // EXP — настройки
                'af_balance_exp_enabled'                 => 'Включить EXP',
                'af_balance_exp_enabled_desc'            => 'Если выключено — опыт не начисляется и не изменяется.',
                'af_balance_exp_per_char'                => 'EXP за символ',
                'af_balance_exp_per_char_desc'           => 'Сколько опыта начислять за 1 символ в сообщении. Пример: 0.02.',
                'af_balance_exp_on_register'              => 'EXP при регистрации',
                'af_balance_exp_on_register_desc'         => 'Сколько опыта выдать пользователю при регистрации.',
                'af_balance_exp_on_accept'                => 'EXP за принятие анкеты',
                'af_balance_exp_on_accept_desc'           => 'Сколько опыта выдать при принятии анкеты/переносе в принятые (по текущей логике CharacterSheets).',

                'af_balance_exp_categories_csv'           => 'EXP: категории (CSV fid)',
                'af_balance_exp_categories_csv_desc'      => 'ID категорий через запятую. Учитываются все дочерние форумы категорий.',
                'af_balance_exp_forums_csv'               => 'EXP: форумы (CSV fid)',
                'af_balance_exp_forums_csv_desc'          => 'ID форумов через запятую.',
                'af_balance_exp_mode'                     => 'EXP: режим выбора',
                'af_balance_exp_mode_desc'                => 'include — начислять только в выбранных; exclude — начислять везде, кроме выбранных.',
                'af_balance_exp_mode_include'             => 'include (только выбранные)',
                'af_balance_exp_mode_exclude'             => 'exclude (всё кроме выбранных)',

                'af_balance_exp_allow_negative_award'     => 'Разрешить отрицательное начисление EXP',
                'af_balance_exp_allow_negative_award_desc'=> 'Если включено — можно начислять EXP со знаком минус (списывать).',
                'af_balance_exp_allow_balance_negative'   => 'Разрешить уход EXP в минус',
                'af_balance_exp_allow_balance_negative_desc'=> 'Если выключено — итоговый баланс EXP не может быть ниже 0.',
                'af_balance_exp_manual_groups'            => 'Группы ручного начисления EXP (CSV gid)',
                'af_balance_exp_manual_groups_desc'       => 'Какие группы могут вручную начислять/списывать EXP (по UI листа персонажа). Пусто = никто.',

                // Credits — настройки
                'af_balance_credits_enabled'                 => 'Включить кредиты',
                'af_balance_credits_enabled_desc'            => 'Если выключено — кредиты не начисляются и не изменяются.',
                'af_balance_credits_per_char'                => 'Кредиты за символ',
                'af_balance_credits_per_char_desc'           => 'Сколько кредитов начислять за 1 символ. Обычно 0. Можно использовать для “зарплаты” за посты.',
                'af_balance_credits_on_register'              => 'Кредиты при регистрации',
                'af_balance_credits_on_register_desc'         => 'Сколько кредитов выдать при регистрации.',
                'af_balance_credits_on_accept'                => 'Кредиты за принятие анкеты',
                'af_balance_credits_on_accept_desc'           => 'Сколько кредитов выдать при принятии анкеты.',

                'af_balance_credits_categories_csv'           => 'Кредиты: категории (CSV fid)',
                'af_balance_credits_categories_csv_desc'      => 'ID категорий через запятую. Учитываются все дочерние форумы.',
                'af_balance_credits_forums_csv'               => 'Кредиты: форумы (CSV fid)',
                'af_balance_credits_forums_csv_desc'          => 'ID форумов через запятую.',
                'af_balance_credits_mode'                     => 'Кредиты: режим выбора',
                'af_balance_credits_mode_desc'                => 'include — начислять только в выбранных; exclude — начислять везде, кроме выбранных.',
                'af_balance_credits_mode_include'             => 'include (только выбранные)',
                'af_balance_credits_mode_exclude'             => 'exclude (всё кроме выбранных)',

                'af_balance_credits_allow_negative_award'      => 'Разрешить отрицательное начисление кредитов',
                'af_balance_credits_allow_negative_award_desc' => 'Если включено — можно списывать кредиты (операции со знаком минус).',
                'af_balance_credits_allow_balance_negative'    => 'Разрешить уход кредитов в минус',
                'af_balance_credits_allow_balance_negative_desc'=> 'Если выключено — итоговый баланс кредитов не может быть ниже 0.',
                'af_balance_credits_manual_groups'             => 'Группы ручного начисления кредитов (CSV gid)',
                'af_balance_credits_manual_groups_desc'        => 'Какие группы могут вручную начислять/списывать кредиты. Пусто = никто.',

                // логи
                'af_balance_tx_enable'       => 'Включить лог транзакций',
                'af_balance_tx_enable_desc'  => 'Записывать все изменения EXP/кредитов в таблицу транзакций.',
                'af_balance_tx_keep_limit'   => 'Лимит транзакций (хранить записей)',
                'af_balance_tx_keep_limit_desc'=> 'Если записей станет больше лимита — самые старые будут удаляться (по механике аддона).',
                'af_balance_blacklist'       => 'Blacklist ассетов Balance',
                'af_balance_blacklist_desc'  => 'По одной строке: script.php. На совпавших страницах assets Balance не подключаются.',

                // ACP страницы (если будешь выводить)
                'af_balance_admin_title'         => 'Баланс',
                'af_balance_admin_transactions'  => 'Транзакции',
            ],
        ],

        'english' => [
            'front' => [
                'af_balance_name'        => 'Balance',
                'af_balance_description' => 'EXP and credits: accruals, deductions, progress and transaction history.',
                'af_balance_exp'         => 'EXP',
                'af_balance_credits'     => 'Credits',
                'af_balance_level'       => 'Level',
                'af_balance_progress'    => 'Progress',
                'af_balance_to_next'     => 'To next level',

                'af_balance_reason_post_chars' => 'Post (by characters)',
                'af_balance_reason_register'   => 'Registration',
                'af_balance_reason_accept'     => 'Character accepted',
                'af_balance_reason_manual'     => 'Manual adjust',
                'af_balance_reason_quest'      => 'Quest',
                'af_balance_reason_event'      => 'Event',
                'af_balance_reason_shop'       => 'Shop',
            ],
            'admin' => [
                'af_balance_group'      => 'Balance',
                'af_balance_group_desc' => 'Settings for EXP and credits, plus transaction log.',

                'af_balance_exp_enabled'                  => 'Enable EXP',
                'af_balance_exp_enabled_desc'             => 'If disabled, EXP will not be awarded or changed.',
                'af_balance_exp_per_char'                 => 'EXP per character',
                'af_balance_exp_per_char_desc'            => 'How much EXP to award per 1 character in a post. Example: 0.02.',
                'af_balance_exp_on_register'              => 'EXP on registration',
                'af_balance_exp_on_register_desc'         => 'How much EXP to give on user registration.',
                'af_balance_exp_on_accept'                => 'EXP on character acceptance',
                'af_balance_exp_on_accept_desc'           => 'How much EXP to give when the character is accepted (per current CharacterSheets logic).',

                'af_balance_exp_categories_csv'           => 'EXP: categories (CSV fid)',
                'af_balance_exp_categories_csv_desc'      => 'Category IDs comma-separated. All child forums are included.',
                'af_balance_exp_forums_csv'               => 'EXP: forums (CSV fid)',
                'af_balance_exp_forums_csv_desc'          => 'Forum IDs comma-separated.',
                'af_balance_exp_mode'                     => 'EXP: selection mode',
                'af_balance_exp_mode_desc'                => 'include = only selected; exclude = everything except selected.',
                'af_balance_exp_mode_include'             => 'include (selected only)',
                'af_balance_exp_mode_exclude'             => 'exclude (everything except selected)',

                'af_balance_exp_allow_negative_award'      => 'Allow negative EXP awards',
                'af_balance_exp_allow_negative_award_desc' => 'If enabled, EXP can be deducted (negative amount).',
                'af_balance_exp_allow_balance_negative'    => 'Allow EXP to go negative',
                'af_balance_exp_allow_balance_negative_desc'=> 'If disabled, resulting EXP cannot go below 0.',
                'af_balance_exp_manual_groups'             => 'Manual EXP groups (CSV gid)',
                'af_balance_exp_manual_groups_desc'        => 'Groups allowed to manually adjust EXP (CharacterSheet UI). Empty = nobody.',

                'af_balance_credits_enabled'                  => 'Enable credits',
                'af_balance_credits_enabled_desc'             => 'If disabled, credits will not be awarded or changed.',
                'af_balance_credits_per_char'                 => 'Credits per character',
                'af_balance_credits_per_char_desc'            => 'How many credits to award per 1 character. Usually 0.',
                'af_balance_credits_on_register'              => 'Credits on registration',
                'af_balance_credits_on_register_desc'         => 'How many credits to give on user registration.',
                'af_balance_credits_on_accept'                => 'Credits on character acceptance',
                'af_balance_credits_on_accept_desc'           => 'How many credits to give when the character is accepted.',

                'af_balance_credits_categories_csv'           => 'Credits: categories (CSV fid)',
                'af_balance_credits_categories_csv_desc'      => 'Category IDs comma-separated. All child forums are included.',
                'af_balance_credits_forums_csv'               => 'Credits: forums (CSV fid)',
                'af_balance_credits_forums_csv_desc'          => 'Forum IDs comma-separated.',
                'af_balance_credits_mode'                     => 'Credits: selection mode',
                'af_balance_credits_mode_desc'                => 'include = only selected; exclude = everything except selected.',
                'af_balance_credits_mode_include'             => 'include (selected only)',
                'af_balance_credits_mode_exclude'             => 'exclude (everything except selected)',

                'af_balance_credits_allow_negative_award'      => 'Allow negative credit awards',
                'af_balance_credits_allow_negative_award_desc' => 'If enabled, credits can be deducted (negative amount).',
                'af_balance_credits_allow_balance_negative'    => 'Allow credits to go negative',
                'af_balance_credits_allow_balance_negative_desc'=> 'If disabled, resulting credits cannot go below 0.',
                'af_balance_credits_manual_groups'             => 'Manual credits groups (CSV gid)',
                'af_balance_credits_manual_groups_desc'        => 'Groups allowed to manually adjust credits. Empty = nobody.',

                'af_balance_tx_enable'        => 'Enable transaction log',
                'af_balance_tx_enable_desc'   => 'Log every EXP/credits change into transactions table.',
                'af_balance_tx_keep_limit'    => 'Transaction keep limit',
                'af_balance_tx_keep_limit_desc'=> 'If the number of logs exceeds this limit, oldest entries are removed.',
                'af_balance_blacklist'          => 'Balance assets blacklist',
                'af_balance_blacklist_desc'     => 'One script.php per line. Balance assets are disabled on matched pages.',

                'af_balance_admin_title'        => 'Balance',
                'af_balance_admin_transactions' => 'Transactions',
            ],
        ],
    ],
];
