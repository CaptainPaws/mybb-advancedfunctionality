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

        $query = $db->simple_select(AF_AAM_TABLE_TYPES, '*', '', ['order_by' => 'id']);
        $alertTypes = [];
        while ($row = $db->fetch_array($query)) {
            $alertTypes[] = $row;
        }

        if ($mybb->request_method === 'post') {
            verify_post_check($mybb->get_input('my_post_key'));

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
            admin_redirect('index.php?module='.AF_PLUGIN_ID.'&af_view=AdvancedAlertsAndMentions');
        }

        $form = new Form('index.php?module='.AF_PLUGIN_ID.'&af_view=AdvancedAlertsAndMentions', 'post');
        echo $form->generate_hidden_field('my_post_key', $mybb->post_code);

        $table = new Table;
        $table->construct_header($lang->af_aam_admin_code, ['width' => '15%']);
        $table->construct_header($lang->af_aam_admin_title_col, ['width' => '35%']);
        $table->construct_header($lang->af_aam_admin_enabled, ['width' => '10%', 'class' => 'align_center']);
        $table->construct_header($lang->af_aam_admin_can_disable, ['width' => '15%', 'class' => 'align_center']);
        $table->construct_header($lang->af_aam_admin_default_user_enabled, ['width' => '15%', 'class' => 'align_center']);

        if (empty($alertTypes)) {
            $table->construct_cell($lang->af_aam_admin_empty, ['colspan' => 5]);
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
                $table->construct_row();
            }
        }

        $table->output($lang->af_aam_admin_title);

        if (!empty($alertTypes)) {
            $buttons[] = $form->generate_submit_button($lang->af_aam_admin_save_types);
            $form->output_submit_wrapper($buttons);
        }

        $form->end();
    }
}