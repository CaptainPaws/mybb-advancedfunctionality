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
        global $db, $mybb, $lang, $page;

        $lang->load('advancedfunctionality_advancedalertsandmentions');

        if ($mybb->request_method === 'post') {
            verify_post_check($mybb->get_input('my_post_key'));
            $code = trim($mybb->get_input('code'));
            $title = trim($mybb->get_input('title'));
            $canDisable = (int)$mybb->get_input('can_disable', MyBB::INPUT_INT);
            $enabled = (int)$mybb->get_input('enabled', MyBB::INPUT_INT);

            if ($code !== '' && $title !== '') {
                $existingId = $db->fetch_field($db->simple_select(AF_AAM_TABLE_TYPES, 'id', "code='".$db->escape_string($code)."'"), 'id');
                $row = [
                    'code'                 => $db->escape_string($code),
                    'title'                => $db->escape_string($title),
                    'enabled'              => $enabled ? 1 : 0,
                    'can_be_user_disabled' => $canDisable ? 1 : 0,
                ];
                if ($existingId) {
                    $db->update_query(AF_AAM_TABLE_TYPES, $row, "id=".(int)$existingId);
                } else {
                    $db->insert_query(AF_AAM_TABLE_TYPES, $row);
                }
                flash_message($lang->af_aam_admin_msg_saved, 'success');
            }
            admin_redirect('index.php?module='.AF_PLUGIN_ID.'&af_view=AdvancedAlertsAndMentions');
        }

        $deleteId = $mybb->get_input('delete', MyBB::INPUT_INT);
        if ($deleteId) {
            verify_post_check($mybb->get_input('my_post_key'));
            $db->delete_query(AF_AAM_TABLE_TYPES, "id=".(int)$deleteId);
            flash_message($lang->af_aam_admin_msg_deleted, 'success');
            admin_redirect('index.php?module='.AF_PLUGIN_ID.'&af_view=AdvancedAlertsAndMentions');
        }

        $table = new Table;
        $table->construct_header($lang->af_aam_admin_code, ['width' => '20%']);
        $table->construct_header($lang->af_aam_admin_title_col, ['width' => '35%']);
        $table->construct_header($lang->af_aam_admin_can_disable, ['width' => '15%', 'class'=>'align_center']);
        $table->construct_header($lang->af_aam_admin_enabled, ['width' => '10%', 'class'=>'align_center']);
        $table->construct_header($lang->af_aam_admin_actions, ['width' => '20%', 'class'=>'align_center']);

        $query = $db->simple_select(AF_AAM_TABLE_TYPES, '*', '', ['order_by' => 'id']);
        if ($db->num_rows($query) === 0) {
            $table->construct_cell($lang->af_aam_admin_empty, ['colspan' => 5, 'class'=>'align_center']);
            $table->construct_row();
        } else {
            while ($row = $db->fetch_array($query)) {
                $table->construct_cell(htmlspecialchars_uni($row['code']));
                $table->construct_cell(htmlspecialchars_uni($row['title']));
                $table->construct_cell($row['can_be_user_disabled'] ? '✓' : '—', ['class'=>'align_center']);
                $table->construct_cell($row['enabled'] ? '✓' : '—', ['class'=>'align_center']);

                $deleteUrl = 'index.php?module='.AF_PLUGIN_ID.'&af_view=AdvancedAlertsAndMentions&delete='.(int)$row['id'].'&my_post_key='.htmlspecialchars_uni($mybb->post_code);
                $deleteLink = '<a href="'.$deleteUrl.'" onclick="return confirm(\''.$lang->af_aam_admin_msg_deleted.'\');">🗑</a>';
                $table->construct_cell($deleteLink, ['class'=>'align_center']);
                $table->construct_row();
            }
        }

        $table->output($lang->af_aam_admin_title);

        $form = new Form('index.php?module='.AF_PLUGIN_ID.'&af_view=AdvancedAlertsAndMentions', 'post');
        echo '<br />';
        echo $form->generate_hidden_field('my_post_key', $mybb->post_code);
        echo '<div class="form_container">';
        echo '<div class="form_row">'.$form->generate_text_box('code', '', ['placeholder'=>'mention']).'</div>';
        echo '<div class="form_row">'.$form->generate_text_box('title', '', ['placeholder'=>$lang->af_aam_alert_type_mention]).'</div>';
        echo '<div class="form_row">'.$form->generate_yes_no_radio('enabled', 1, true, ['label'=>$lang->af_aam_admin_enabled]).'</div>';
        echo '<div class="form_row">'.$form->generate_yes_no_radio('can_disable', 1, true, ['label'=>$lang->af_aam_admin_can_disable]).'</div>';
        echo '<div class="form_row"><input type="submit" class="button" value="'.htmlspecialchars_uni($lang->af_aam_admin_add).'" /></div>';
        echo '</div>';
        $form->end();
    }
}