<?php
// ACP controller for Advanced Alerts and Mentions
if (!defined('IN_MYBB')) { die('No direct access'); }
if (!defined('AF_ADDONS')) { die('AdvancedFunctionality core required'); }

// Подтягиваем основной файл аддона, чтобы были константы и функции
if (!defined('AF_AAM_ID')) {
    require_once AF_ADDONS . 'advancedalertsandmentions/advancedalertsandmentions.php';
}

class AF_Admin_AdvancedAlertsAndMentions
{
    public static function dispatch(): void
    {
        global $db, $mybb, $lang;

        $lang->load('advancedfunctionality_advancedalertsandmentions');

        if (!$db->field_exists('default_user_enabled', AF_AAM_TABLE_TYPES)) {
            $db->add_column(AF_AAM_TABLE_TYPES, 'default_user_enabled', "TINYINT(1) NOT NULL DEFAULT 1 AFTER can_be_user_disabled");
        }

        $baseUrl = 'index.php?module=' . AF_PLUGIN_ID . '&af_view=' . AF_AAM_ID;
        $protectedCodes = ['rep', 'pm', 'post_threadauthor', 'subscribed_thread', 'quoted', 'mention'];

        // Удаление пользовательского типа
        if ($mybb->get_input('delete', MyBB::INPUT_INT)) {
            $deleteId = (int)$mybb->get_input('delete', MyBB::INPUT_INT);
            verify_post_check($mybb->get_input('my_post_key')); // GET-параметр, но с защитой

            $existing = $db->fetch_array($db->simple_select(AF_AAM_TABLE_TYPES, '*', "id={$deleteId}"));
            if ($existing && !in_array($existing['code'], $protectedCodes, true)) {
                $db->delete_query(AF_AAM_TABLE_TYPES, "id={$deleteId}");
                flash_message($lang->af_aam_admin_msg_deleted, 'success');
            }

            admin_redirect($baseUrl);
        }

        $query = $db->simple_select(AF_AAM_TABLE_TYPES, '*', '', ['order_by' => 'id']);
        $alertTypes = [];
        while ($row = $db->fetch_array($query)) {
            $alertTypes[] = $row;
        }

        if ($mybb->request_method === 'post') {
            verify_post_check($mybb->get_input('my_post_key'));

            $action = $mybb->get_input('af_aam_action');

            if ($action === 'create') {
                $code = trim($mybb->get_input('new_alert_code'));
                $title = trim($mybb->get_input('new_alert_title'));
                $enabled = $mybb->get_input('new_alert_enabled', MyBB::INPUT_INT) ? 1 : 0;
                $canDisable = $mybb->get_input('new_alert_can_be_user_disabled', MyBB::INPUT_INT) ? 1 : 0;
                $defaultUserEnabled = $mybb->get_input('new_alert_default_user_enabled', MyBB::INPUT_INT) ? 1 : 0;

                if ($code !== '' && preg_match('/^[a-z0-9_]+$/i', $code)) {
                    $existing = $db->fetch_array($db->simple_select(AF_AAM_TABLE_TYPES, 'id', "code='" . $db->escape_string($code) . "'"));
                    if (!$existing) {
                        af_aam_register_type($code, $title, $canDisable, $defaultUserEnabled, $enabled);
                        flash_message($lang->af_aam_admin_msg_saved, 'success');
                    }
                }

                admin_redirect($baseUrl);
            }

            $enabledAlertTypes = array_map('intval', array_keys($mybb->get_input('alert_types_enabled', MyBB::INPUT_ARRAY)));
            $canBeUserDisabled = array_map('intval', array_keys($mybb->get_input('alert_types_can_be_user_disabled', MyBB::INPUT_ARRAY)));
            $defaultUserEnabled = array_map('intval', array_keys($mybb->get_input('alert_types_default_user_enabled', MyBB::INPUT_ARRAY)));

            foreach ($alertTypes as $alertType) {
                $id = (int)$alertType['id'];
                $db->update_query(AF_AAM_TABLE_TYPES, [
                    'enabled'              => in_array($id, $enabledAlertTypes, true) ? 1 : 0,
                    'can_be_user_disabled' => in_array($id, $canBeUserDisabled, true) ? 1 : 0,
                    'default_user_enabled' => in_array($id, $defaultUserEnabled, true) ? 1 : 0,
                ], "id={$id}");
            }

            flash_message($lang->af_aam_admin_msg_saved, 'success');
            admin_redirect($baseUrl);
        }

        $form = new Form($baseUrl, 'post');
        echo $form->generate_hidden_field('my_post_key', $mybb->post_code);
        echo $form->generate_hidden_field('af_aam_action', 'save');

        $table = new Table;
        $table->construct_header($lang->af_aam_admin_code, ['width' => '15%']);
        $table->construct_header($lang->af_aam_admin_title_col, ['width' => '35%']);
        $table->construct_header($lang->af_aam_admin_enabled, ['width' => '10%', 'class' => 'align_center']);
        $table->construct_header($lang->af_aam_admin_can_disable, ['width' => '15%', 'class' => 'align_center']);
        $table->construct_header($lang->af_aam_admin_default_user_enabled, ['width' => '15%', 'class' => 'align_center']);
        $table->construct_header($lang->af_aam_admin_actions, ['width' => '10%', 'class' => 'align_center']);

        if (empty($alertTypes)) {
            $table->construct_cell($lang->af_aam_admin_empty, ['colspan' => 6]);
            $table->construct_row();
        } else {
            foreach ($alertTypes as $alertType) {
                $code = htmlspecialchars_uni($alertType['code']);
                $titleKey = 'af_aam_alert_type_' . $alertType['code'];
                $title = $alertType['title'] ?: ($lang->{$titleKey} ?? $alertType['code']);
                $table->construct_cell($code);
                $table->construct_cell(htmlspecialchars_uni($title));
                $table->construct_cell(
                    $form->generate_check_box('alert_types_enabled[' . $alertType['id'] . ']', '1', '', ['checked' => (int)$alertType['enabled'] === 1]),
                    ['class' => 'align_center']
                );
                $table->construct_cell(
                    $form->generate_check_box(
                        'alert_types_can_be_user_disabled[' . $alertType['id'] . ']',
                        '1',
                        '',
                        ['checked' => (int)$alertType['can_be_user_disabled'] === 1]
                    ),
                    ['class' => 'align_center']
                );
                $table->construct_cell(
                    $form->generate_check_box(
                        'alert_types_default_user_enabled[' . $alertType['id'] . ']',
                        '1',
                        '',
                        ['checked' => (int)($alertType['default_user_enabled'] ?? 1) === 1]
                    ),
                    ['class' => 'align_center']
                );

                $actions = '-';
                if (!in_array($alertType['code'], $protectedCodes, true)) {
                    $deleteLink = $baseUrl . '&delete=' . (int)$alertType['id'] . '&my_post_key=' . $mybb->post_code;
                    $actions = '<a href="' . $deleteLink . '" onclick="return confirm(\'' . addslashes($lang->af_aam_admin_confirm_delete) . '\');">' . $lang->af_aam_admin_delete . '</a>';
                }
                $table->construct_cell($actions, ['class' => 'align_center']);
                $table->construct_row();
            }
        }

        $table->output($lang->af_aam_admin_title);

        if (!empty($alertTypes)) {
            $buttons[] = $form->generate_submit_button($lang->af_aam_admin_save_types);
            $form->output_submit_wrapper($buttons);
        }

        $form->end();

        // Форма добавления пользовательского типа
        $addForm = new Form($baseUrl, 'post');
        echo $addForm->generate_hidden_field('my_post_key', $mybb->post_code);
        echo $addForm->generate_hidden_field('af_aam_action', 'create');

        $addTable = new Table;
        $addTable->construct_header($lang->af_aam_admin_code);
        $addTable->construct_header($lang->af_aam_admin_title_col);
        $addTable->construct_header($lang->af_aam_admin_enabled, ['class' => 'align_center']);
        $addTable->construct_header($lang->af_aam_admin_can_disable, ['class' => 'align_center']);
        $addTable->construct_header($lang->af_aam_admin_default_user_enabled, ['class' => 'align_center']);

        $addTable->construct_cell(
            $addForm->generate_text_box('new_alert_code', '', ['style' => 'width:95%;', 'placeholder' => 'custom_code'])
        );
        $addTable->construct_cell(
            $addForm->generate_text_box('new_alert_title', '', ['style' => 'width:95%;', 'placeholder' => $lang->af_aam_admin_title_placeholder])
        );
        $addTable->construct_cell(
            $addForm->generate_check_box('new_alert_enabled', '1', '', ['checked' => true]),
            ['class' => 'align_center']
        );
        $addTable->construct_cell(
            $addForm->generate_check_box('new_alert_can_be_user_disabled', '1', '', ['checked' => true]),
            ['class' => 'align_center']
        );
        $addTable->construct_cell(
            $addForm->generate_check_box('new_alert_default_user_enabled', '1', '', ['checked' => true]),
            ['class' => 'align_center']
        );
        $addTable->construct_row();

        $addTable->output($lang->af_aam_admin_add);

        $addButtonLabel = $lang->af_aam_admin_add_button ?? ($lang->af_aam_admin_add ?? $lang->af_aam_admin_save_types ?? 'Save');
        $addButtons[] = $addForm->generate_submit_button($addButtonLabel);
        $addForm->output_submit_wrapper($addButtons);
        $addForm->end();
    }
}