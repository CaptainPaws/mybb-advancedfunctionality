<?php
/**
 * Advanced QuickReply — внутренний аддон AF
 * MyBB 1.8.38–1.8.39, PHP 8.0–8.4
 *
 * ВЕРСИЯ БЕЗ BB-ФУНКЦИОНАЛА.
 *
 * Делает форму быстрого ответа полноценным редактором:
 *  - добавляет {$codebuttons} в шаблон showthread_quickreply
 *  - подготавливает $codebuttons и $smilieinserter аналогично newreply.php
 */

if (!defined('IN_MYBB')) {
    die('No direct access');
}

/**
 * Установка аддона: создаём настройки и патчим showthread_quickreply.
 */
function af_advancedquickreply_install(): void
{
    global $db;

    // === группа настроек ===
    $query = $db->simple_select('settinggroups', 'gid', "name='af_advancedquickreply'");
    $group = $db->fetch_array($query);
    if (!$group) {
        $group = [
            'name'        => 'af_advancedquickreply',
            'title'       => 'AF: Advanced QuickReply',
            'description' => 'Расширенный быстрый ответ с полным редактором.',
            'disporder'   => 10,
            'isdefault'   => 0,
        ];
        $gid = (int)$db->insert_query('settinggroups', $group);
    } else {
        $gid = (int)$group['gid'];
    }

    // === единственная настройка: вкл/выкл ===
    $setting_name = 'af_advancedquickreply_enabled';

    $existing = $db->simple_select(
        'settings',
        'sid',
        "name='".$db->escape_string($setting_name)."'",
        ['limit' => 1]
    );
    if (!$db->fetch_array($existing)) {
        $db->insert_query('settings', [
            'name'        => $setting_name,
            'title'       => 'Включить Advanced QuickReply',
            'description' => 'Если включено, в форме быстрого ответа отображается полный редактор с BB-кодами.',
            'optionscode' => 'yesno',
            'value'       => '1',
            'disporder'   => 1,
            'gid'         => $gid,
        ]);
    }

    if (function_exists('rebuild_settings')) {
        rebuild_settings();
    }

    // === шаблоны showthread_quickreply: добавляем {$codebuttons} после textarea ===
    // Ставим свой маркер <!-- advancedquickreply -->, чтобы не дублировать при повторной установке.
    $query = $db->simple_select('templates', 'tid,template', "title='showthread_quickreply'");
    while ($tpl = $db->fetch_array($query)) {
        // уже патчен — пропускаем
        if (strpos($tpl['template'], 'advancedquickreply') !== false) {
            continue;
        }

        $updated = preg_replace(
            '#</textarea>#i',
            '</textarea><!-- advancedquickreply -->{$codebuttons}',
            $tpl['template'],
            1
        );

        if ($updated !== null && $updated !== $tpl['template']) {
            $db->update_query('templates', [
                'template' => $db->escape_string($updated),
            ], 'tid='.(int)$tpl['tid']);
        }
    }

    // ВАЖНО:
    // Никаких колонок/таблиц под иконки BB-кодов здесь больше нет.
    // Всё, что касается af_aqr_defaultcodes, af_button_icon, af_fa_icon — уедет в отдельный подплагин.
}

/**
 * Удаление аддона: вычищаем только свои настройки и патч шаблона.
 */
function af_advancedquickreply_uninstall(): void
{
    global $db;

    // Настройки
    $db->delete_query('settings', "name='af_advancedquickreply_enabled'");
    $db->delete_query('settinggroups', "name='af_advancedquickreply'");

    if (function_exists('rebuild_settings')) {
        rebuild_settings();
    }

    // Шаблоны: убираем только наш маркер и {$codebuttons}, добавленные этим аддоном.
    $query = $db->simple_select('templates', 'tid,template', "title='showthread_quickreply'");
    while ($tpl = $db->fetch_array($query)) {
        if (strpos($tpl['template'], 'advancedquickreply') === false) {
            continue;
        }

        // аккуратно вырезаем именно вставленную нами часть
        $updated = str_replace('<!-- advancedquickreply -->{$codebuttons}', '', $tpl['template']);

        if ($updated !== $tpl['template']) {
            $db->update_query('templates', [
                'template' => $db->escape_string($updated),
            ], 'tid='.(int)$tpl['tid']);
        }
    }

    // НИЧЕГО не трогаем в таблицах mycode / af_aqr_defaultcodes.
    // Иконки и BB-функционал — зона ответственности другого подплагина.
}

/**
 * Проверяем, установлен ли аддон.
 */
function af_advancedquickreply_is_installed(): bool
{
    global $db;

    $query = $db->simple_select('settinggroups', 'gid', "name='af_advancedquickreply'", ['limit' => 1]);
    return (bool)$db->fetch_array($query);
}

/**
 * Активация/деактивация — заглушки, управление идёт через настройку enabled.
 */
function af_advancedquickreply_activate(): void
{
    // Ничего не делаем.
}

function af_advancedquickreply_deactivate(): void
{
    // Ничего не делаем.
}

/**
 * Инициализация на фронте: регистрируем свои хуки.
 * Вызывается из ядра AdvancedFunctionality в global_start.
 */
function af_advancedquickreply_init(): void
{
    global $mybb, $plugins;

    // В админке/модпанели не работаем
    if (defined('IN_ADMINCP') || defined('IN_MODCP')) {
        return;
    }

    // Выключено настройкой — выходим
    if (empty($mybb->settings['af_advancedquickreply_enabled'])) {
        return;
    }

    if (!isset($plugins) || !is_object($plugins)) {
        return;
    }

    // Подвешиваемся к showthread_start, чтобы подготовить $codebuttons / $smilieinserter.
    $plugins->add_hook('showthread_start', 'af_advancedquickreply_showthread_start');

    // ВАЖНО: Больше НЕТ хука pre_output_page.
    // Никакой правки готового HTML / JS / toolbar'а здесь не происходит.
}

/**
 * Хук showthread_start: превращаем quick reply в полный редактор.
 */
function af_advancedquickreply_showthread_start(): void
{
    global $mybb, $forum, $codebuttons, $smilieinserter;

    // Админка/модпанель — мимо
    if (defined('IN_ADMINCP') || defined('IN_MODCP')) {
        return;
    }

    // Плагин выключен
    if (empty($mybb->settings['af_advancedquickreply_enabled'])) {
        return;
    }

    // Логика близка к newreply.php — уважаем настройки доски и форума
    if (!empty($mybb->settings['bbcodeinserter'])
        && ($forum['allowmycode'] != 0 || $forum['allowsmilies'] != 0)
        && ($mybb->user['uid'] == 0 || !empty($mybb->user['showcodebuttons']))
    ) {
        if (!function_exists('build_mycode_inserter')) {
            require_once MYBB_ROOT.'inc/functions.php';
        }

        // Quick reply использует textarea с id="message"
        $codebuttons = build_mycode_inserter('message', true);
    }

    if (!empty($mybb->settings['smilieinserter']) && $forum['allowsmilies'] != 0) {
        if (!function_exists('build_clickable_smilies')) {
            require_once MYBB_ROOT.'inc/functions.php';
        }

        $smilieinserter = build_clickable_smilies();
    }
}
