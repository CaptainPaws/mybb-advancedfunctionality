<?php
if (!defined('IN_MYBB')) { die('No direct access'); }
if (!defined('AF_ADDONS')) { die('AdvancedFunctionality core required'); }

/**
 * Контроллер админ-экрана внутри AF:
 * /admin/index.php?module=advancedfunctionality&addon=AdvancedAlerts
 */
class AF_Admin_AdvancedAlerts
{
    public static function dispatch()
    {
        global $mybb, $db, $page;

        // Заголовки/хлебные крошки рендерит AF-роутер, но таблицы/формы стандартные
        require_once MYBB_ROOT.'inc/functions.php';
        if (!function_exists('flash_message')) {
            function flash_message($message, $type='success') {
                // На случай, если вызвали вне стандартной среды
                echo '<div class="flash '.$type.'">'.$message.'</div>';
            }
        }

        // Обработка POST
        $do = $mybb->get_input('do');
        if ($mybb->request_method === 'post') {
            verify_post_check($mybb->get_input('my_post_key'));

            if ($do === 'add') {
                $code = trim($mybb->get_input('code'));
                $title= trim($mybb->get_input('title'));
                $cud  = (int)$mybb->get_input('can_be_user_disabled');
                $en   = (int)$mybb->get_input('enabled');

                if ($code !== '') {
                    afaa_register_type([
                        'code'=>$code,
                        'title'=> ($title !== '' ? $title : $code),
                        'can_be_user_disabled'=>$cud,
                        'enabled'=>$en
                    ]);
                    flash_message('Тип добавлен', 'success');
                } else {
                    flash_message('Укажите код типа', 'error');
                }
                self::redirectSelf();
            }

            if ($do === 'toggle') {
                $id    = (int)$mybb->get_input('id');
                $field = $mybb->get_input('field');
                if (in_array($field, ['enabled','can_be_user_disabled'], true)) {
                    $row = $db->fetch_array($db->simple_select('alert_types','id,'.$field,"id={$id}",['limit'=>1]));
                    if ($row) {
                        $db->update_query('alert_types', [$field => ((int)!$row[$field])], "id={$id}");
                        flash_message('Изменения сохранены', 'success');
                    }
                }
                self::redirectSelf();
            }

            if ($do === 'delete') {
                $id = (int)$mybb->get_input('id');
                $db->delete_query('alert_types', "id={$id}");
                flash_message('Тип удалён', 'success');
                self::redirectSelf();
            }
        }

        // ====== РЕНДЕР СПИСКА ======
        echo '<h2>Типы уведомлений</h2>';

        $table = new Table;
        $table->construct_header('ID', ['width'=>'6%']);
        $table->construct_header('Code', ['width'=>'20%']);
        $table->construct_header('Title', ['width'=>'34%']);
        $table->construct_header('User can disable', ['width'=>'12%']);
        $table->construct_header('Enabled', ['width'=>'10%']);
        $table->construct_header('Actions', ['width'=>'18%']);

        $q = $db->simple_select('alert_types','*', '', ['order_by'=>'id','order_dir'=>'ASC']);
        while ($t = $db->fetch_array($q)) {
            $toggle_cud = '<a href="'.self::url(['do'=>'toggle','field'=>'can_be_user_disabled','id'=>$t['id']]).'" class="button">'.((int)$t['can_be_user_disabled'] ? 'Да' : 'Нет').'</a>';
            $toggle_en  = '<a href="'.self::url(['do'=>'toggle','field'=>'enabled','id'=>$t['id']]).'" class="button">'.((int)$t['enabled'] ? 'Вкл' : 'Выкл').'</a>';
            $del        = '<a href="'.self::url(['do'=>'delete','id'=>$t['id']]).'" class="button" onclick="return confirm(\'Удалить тип?\')">Удалить</a>';

            $table->construct_cell((int)$t['id']);
            $table->construct_cell(htmlspecialchars($t['code']));
            $table->construct_cell(htmlspecialchars($t['title'] ?: $t['code']));
            $table->construct_cell($toggle_cud);
            $table->construct_cell($toggle_en);
            $table->construct_cell($del);
            $table->construct_row();
        }
        if ($table->num_rows() === 0) {
            $table->construct_cell('Нет типов', ['colspan'=>6]);
            $table->construct_row();
        }
        $table->output('Типы уведомлений');

        // ====== ФОРМА ДОБАВЛЕНИЯ ======
        echo '<br/>';
        $form = new Form(self::url(['do'=>'add']), 'post', 'afaa_addtype');
        $form_container = new FormContainer('Добавить тип');
        $form_container->output_row('Code', 'Уникальный код (латиница/цифры/подчёркивание).', $form->generate_text_box('code','', ['id'=>'code']), 'code');
        $form_container->output_row('Title', 'Заголовок типа (опционально).', $form->generate_text_box('title','', ['id'=>'title']), 'title');
        $form_container->output_row('Можно отключать пользователю', '', $form->generate_yes_no_radio('can_be_user_disabled', 1));
        $form_container->output_row('Включён', '', $form->generate_yes_no_radio('enabled', 1));
        $form_container->end();
        $buttons = [];
        $buttons[] = $form->generate_submit_button('Добавить');
        $form->output_submit_wrapper($buttons);
        $form->end();
    }

    private static function url(array $params = []): string
    {
        global $mybb;
        $base = 'index.php?module=advancedfunctionality&addon=AdvancedAlerts';
        $q = '';
        if (!empty($params)) {
            $pairs = [];
            foreach ($params as $k=>$v) $pairs[] = $k.'='.urlencode((string)$v);
            $q = '&'.implode('&',$pairs);
        }
        // добавляем пост-ключ для действий
        $q .= '&my_post_key='.$mybb->post_code;
        return $base.$q;
    }

    private static function redirectSelf(): void
    {
        header('Location: '.self::url());
        exit;
    }
}
