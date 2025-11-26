<?php
if (!defined('IN_MYBB')) die('No direct access');

class AF_Admin_AdvancedRules
{
    public static function dispatch()
    {
        global $mybb, $page, $db, $lang, $templates;

        $sub = $mybb->get_input('do');

        // === ЖЕСТКО ПОДКЛЮЧАЕМ SCEDITOR В ТЕЛО СТРАНИЦЫ (НЕ В extra_header) ===
        // Порядок: jQuery (фолбек) -> sceditor + bbcode -> твои bb-коды -> стили контента
        echo '
<script>if(typeof window.jQuery==="undefined"){document.write(\'<script src="../jscripts/jquery.js"><\/script>\');}</script>
<link rel="stylesheet" href="../jscripts/sceditor/themes/default.min.css" type="text/css" />
<link rel="stylesheet" href="../jscripts/sceditor/themes/content/default.min.css" type="text/css" />
<script src="../jscripts/sceditor/jquery.sceditor.min.js"></script>
<script src="../jscripts/sceditor/jquery.sceditor.bbcode.min.js"></script>
<script src="../jscripts/bbcodes_sceditor.js"></script>
';

        // ==============================
        // POST-операции (CSRF)
        // ==============================
        if ($mybb->request_method === 'post') {
            verify_post_check($mybb->get_input('my_post_key'));

            if ($sub === 'add_cat') {
                $title = trim($mybb->get_input('title'));
                $desc  = trim($mybb->get_input('description'));
                $ord   = max(1, (int)$mybb->get_input('disporder'));

                if ($title !== '') {
                    $db->insert_query('af_rules_categories', [
                        'title'       => $db->escape_string($title),
                        'description' => $db->escape_string($desc),
                        'disporder'   => $ord
                    ]);
                    flash_message('Категория добавлена.', 'success');
                } else {
                    flash_message('Название категории не может быть пустым.', 'error');
                }
                admin_redirect('index.php?module=advancedfunctionality&af_view=advancedrules');
            }

            if ($sub === 'del_cat') {
                $id = (int)$mybb->get_input('id');
                if ($id) {
                    $db->delete_query('af_rules_categories', "id={$id}");
                    flash_message('Категория удалена (правила из неё тоже).', 'success');
                }
                admin_redirect('index.php?module=advancedfunctionality&af_view=advancedrules');
            }

            if ($sub === 'save_cat') {
                $id    = (int)$mybb->get_input('id');
                $title = trim($mybb->get_input('title'));
                $desc  = trim($mybb->get_input('description'));
                $ord   = max(1, (int)$mybb->get_input('disporder'));

                if ($id && $title !== '') {
                    $db->update_query('af_rules_categories', [
                        'title'       => $db->escape_string($title),
                        'description' => $db->escape_string($desc),
                        'disporder'   => $ord
                    ], "id={$id}");
                    flash_message('Категория обновлена.', 'success');
                } else {
                    flash_message('Проверь название категории.', 'error');
                }
                admin_redirect('index.php?module=advancedfunctionality&af_view=advancedrules');
            }

            if ($sub === 'add_rule') {
                $cid   = (int)$mybb->get_input('cid');
                $title = trim($mybb->get_input('title'));
                $body  = trim($mybb->get_input('body'));
                $ord   = max(1, (int)$mybb->get_input('disporder'));
                if ($cid && $title !== '') {
                    $db->insert_query('af_rules_items', [
                        'cid'       => $cid,
                        'title'     => $db->escape_string($title),
                        'body'      => $db->escape_string($body),
                        'disporder' => $ord
                    ]);
                    flash_message('Правило добавлено.', 'success');
                } else {
                    flash_message('Выбери категорию и введи заголовок.', 'error');
                }
                admin_redirect('index.php?module=advancedfunctionality&af_view=advancedrules');
            }

            if ($sub === 'save_rule') {
                $id    = (int)$mybb->get_input('id');
                $cid   = (int)$mybb->get_input('cid');
                $title = trim($mybb->get_input('title'));
                $body  = trim($mybb->get_input('body'));
                $ord   = max(1, (int)$mybb->get_input('disporder'));

                if ($id && $cid && $title !== '') {
                    $db->update_query('af_rules_items', [
                        'cid'       => $cid,
                        'title'     => $db->escape_string($title),
                        'body'      => $db->escape_string($body),
                        'disporder' => $ord
                    ], "id={$id}");
                    flash_message('Правило обновлено.', 'success');
                } else {
                    flash_message('Проверь категорию и заголовок.', 'error');
                }
                admin_redirect('index.php?module=advancedfunctionality&af_view=advancedrules');
            }

            if ($sub === 'del_rule') {
                $id = (int)$mybb->get_input('id');
                if ($id) {
                    $db->delete_query('af_rules_items', "id={$id}");
                    if ($db->affected_rows()) flash_message('Правило удалено.', 'success');
                    else flash_message('Правило не найдено или уже удалено.', 'error');
                }
                admin_redirect('index.php?module=advancedfunctionality&af_view=advancedrules');
            }
        }

        // ============================== РЕДАКТ-СТРАНИЦЫ ==============================
        if ($sub === 'edit_rule') {
            $id = (int)$mybb->get_input('id');
            if (!$id) { flash_message('Не передан ID правила.', 'error'); admin_redirect('index.php?module=advancedfunctionality&af_view=advancedrules'); }

            $rule = $db->fetch_array($db->simple_select('af_rules_items', '*', "id={$id}", ['limit'=>1]));
            if (!$rule) { flash_message('Правило не найдено.', 'error'); admin_redirect('index.php?module=advancedfunctionality&af_view=advancedrules'); }

            $cats = [];
            $q = $db->simple_select('af_rules_categories', '*', '', ['order_by'=>'disporder, id']);
            while ($r = $db->fetch_array($q)) { $cats[(int)$r['id']] = $r['title']; }

            echo '<div class="group"><h3>Редактировать правило</h3><div class="border_wrapper">';
            $form = new Form("index.php?module=advancedfunctionality&af_view=advancedrules&do=save_rule", 'post', '', 1);
            echo '<table class="general formtable">';
            echo '<tr><td>Категория</td><td>'.$form->generate_select_box('cid', $cats, (int)$rule['cid'], ['style'=>'width:390px']).'</td></tr>';
            echo '<tr><td>Заголовок</td><td>'.$form->generate_text_box('title', htmlspecialchars_uni($rule['title']), ['style'=>'width:380px']).'</td></tr>';
            echo '<tr><td>Текст</td><td>'.$form->generate_text_area('body', htmlspecialchars_uni($rule['body']), ['style'=>'width:520px;height:220px']).'</td></tr>';
            echo '<tr><td>Порядок</td><td>'.$form->generate_text_box('disporder', (int)$rule['disporder'], ['style'=>'width:120px']).'</td></tr>';
            echo '</table>';
            echo $form->generate_hidden_field('id', (int)$rule['id']);
            echo '<div class="submit_align">'.$form->generate_submit_button('Сохранить изменения', ['class'=>'submit_button button']).'</div>';
            $form->end();
            echo '</div></div>';

            echo '<div class="group"><div class="border_wrapper" style="padding:12px;"><a href="index.php?module=advancedfunctionality&af_view=advancedrules" class="button">← Вернуться к списку</a></div></div>';

            self::echo_sceditor_init();
            return;
        }

        if ($sub === 'edit_cat') {
            $id = (int)$mybb->get_input('id');
            if (!$id) { flash_message('Не передан ID категории.', 'error'); admin_redirect('index.php?module=advancedfunctionality&af_view=advancedrules'); }

            $cat = $db->fetch_array($db->simple_select('af_rules_categories', '*', "id={$id}", ['limit'=>1]));
            if (!$cat) { flash_message('Категория не найдена.', 'error'); admin_redirect('index.php?module=advancedfunctionality&af_view=advancedrules'); }

            echo '<div class="group"><h3>Редактировать категорию</h3><div class="border_wrapper">';
            $form = new Form("index.php?module=advancedfunctionality&af_view=advancedrules&do=save_cat", 'post', '', 1);
            echo '<table class="general formtable">';
            echo '<tr><td>Название</td><td>'.$form->generate_text_box('title', htmlspecialchars_uni($cat['title']), ['style'=>'width:380px']).'</td></tr>';
            echo '<tr><td>Описание</td><td>'.$form->generate_text_area('description', htmlspecialchars_uni((string)$cat['description']), ['style'=>'width:520px;height:180px']).'</td></tr>';
            echo '<tr><td>Порядок</td><td>'.$form->generate_text_box('disporder', (int)$cat['disporder'], ['style'=>'width:120px']).'</td></tr>';
            echo '</table>';
            echo $form->generate_hidden_field('id', (int)$cat['id']);
            echo '<div class="submit_align">'.$form->generate_submit_button('Сохранить изменения', ['class'=>'submit_button button']).'</div>';
            $form->end();
            echo '</div></div>';

            echo '<div class="group"><div class="border_wrapper" style="padding:12px;"><a href="index.php?module=advancedfunctionality&af_view=advancedrules" class="button">← Вернуться к списку</a></div></div>';

            self::echo_sceditor_init();
            return;
        }

        // ============================== ОСНОВНАЯ СТРАНИЦА ==============================

        // Добавить категорию
        $form = new Form("index.php?module=advancedfunctionality&af_view=advancedrules&do=add_cat", 'post', '', 1);
        echo '<div class="group"><h3>Добавить категорию</h3><div class="border_wrapper">';
        echo '<table class="general formtable">';
        echo '<tr><td>Название</td><td>'.$form->generate_text_box('title','', ['style'=>'width:380px']).'</td></tr>';
        echo '<tr><td>Описание</td><td>'.$form->generate_text_area('description','', ['style'=>'width:520px;height:140px']).'</td></tr>';
        echo '<tr><td>Порядок</td><td>'.$form->generate_text_box('disporder','1', ['style'=>'width:120px']).'</td></tr>';
        echo '</table>';
        echo '<div class="submit_align">'.$form->generate_submit_button('Добавить категорию', ['class'=>'submit_button button']).'</div>';
        echo '</div></div>';
        $form->end();

        // Категории
        $cats = [];
        $q = $db->simple_select('af_rules_categories', '*', '', ['order_by'=>'disporder, id']);
        while ($r = $db->fetch_array($q)) { $cats[$r['id']] = $r['title']; }

        // Добавить правило
        $form = new Form("index.php?module=advancedfunctionality&af_view=advancedrules&do=add_rule", 'post', '', 1);
        echo '<div class="group"><h3>Добавить правило</h3><div class="border_wrapper">';
        echo '<table class="general formtable">';
        echo '<tr><td>Категория</td><td>'.$form->generate_select_box('cid', $cats, 0, ['style'=>'width:390px']).'</td></tr>';
        echo '<tr><td>Заголовок</td><td>'.$form->generate_text_box('title','', ['style'=>'width:380px']).'</td></tr>';
        echo '<tr><td>Текст</td><td>'.$form->generate_text_area('body','', ['style'=>'width:520px;height:160px']).'</td></tr>';
        echo '<tr><td>Порядок</td><td>'.$form->generate_text_box('disporder','1', ['style'=>'width:120px']).'</td></tr>';
        echo '</table>';
        echo '<div class="submit_align">'.$form->generate_submit_button('Добавить правило', ['class'=>'submit_button button']).'</div>';
        echo '</div></div>';
        $form->end();

        // Список категорий и правил
        echo '<div class="group"><h3>Категории и правила</h3><div class="border_wrapper">';

        $q = $db->simple_select('af_rules_categories', '*', '', ['order_by'=>'disporder, id']);
        while ($cat = $db->fetch_array($q)) {
            echo '<div class="group_title">'.htmlspecialchars_uni($cat['title']).'</div>';
            $cat_desc = trim((string)($cat['description'] ?? ''));
            if ($cat_desc !== '') {
                echo '<div class="smalltext" style="margin:6px 0 12px 6px; max-width: 900px; color:#666;">'
                   . nl2br(htmlspecialchars_uni($cat_desc))
                   . '</div>';
            }

            // Кнопки категории
            $del = new Form("index.php?module=advancedfunctionality&af_view=advancedrules&do=del_cat", 'post', '', 1);
            $cat_edit_url = 'index.php?module=advancedfunctionality&af_view=advancedrules&do=edit_cat&id='.(int)$cat['id'];
            echo '<div class="float_right" style="margin:6px;">'
               . '<a href="'.$cat_edit_url.'" class="popup_button" style="margin-right:6px;">Редактировать категорию</a>'
               . $del->generate_hidden_field('id', (int)$cat['id'])
               . $del->generate_submit_button('Удалить категорию', ['class'=>'submit_button button'])
               . '</div>';
            $del->end();

            // Таблица правил
            $t = new Table;
            $t->construct_header('Заголовок', ['width'=>'30%']);
            $t->construct_header('Текст');
            $t->construct_header('Порядок', ['class'=>'align_center', 'width'=>'8%']);
            $t->construct_header('Действия', ['class'=>'align_center', 'width'=>'18%']);

            $qq = $db->simple_select('af_rules_items', '*', "cid=".(int)$cat['id'], ['order_by'=>'disporder, id']);
            $has = false;
            while ($it = $db->fetch_array($qq)) {
                $has = true;
                $t->construct_cell(htmlspecialchars_uni($it['title']));
                $t->construct_cell(nl2br(htmlspecialchars_uni($it['body'])));
                $t->construct_cell((int)$it['disporder'], ['class'=>'align_center']);

                $edit_url = 'index.php?module=advancedfunctionality&af_view=advancedrules&do=edit_rule&id='.(int)$it['id'];
                $edit_btn = '<a href="'.$edit_url.'" class="popup_button">Редактировать</a>';

                $del_btn  = '<form action="index.php?module=advancedfunctionality&af_view=advancedrules&do=del_rule" method="post" style="display:inline-block;margin-left:6px;">'
                          . '<input type="hidden" name="my_post_key" value="'.$mybb->post_code.'">'
                          . '<input type="hidden" name="id" value="'.(int)$it['id'].'">'
                          . '<input type="submit" class="submit_button button" value="Удалить" onclick="return confirm(\'Удалить это правило?\');">'
                          . '</form>';

                $t->construct_cell($edit_btn.' '.$del_btn, ['class'=>'align_center']);
                $t->construct_row();
            }
            if (!$has) {
                $t->construct_cell('<em class="smalltext" style="color:#666;">Правил нет</em>', ['colspan'=>4, 'class'=>'align_center']);
                $t->construct_row();
            }
            $t->output('');
        }
        echo '</div></div>';

        // ИНИЦИАЛИЗАЦИЯ SCEDITOR: ЗАМЕНЯЕМ КОНКРЕТНЫЕ TEXTAREA
        self::echo_sceditor_init();
    }

    private static function echo_sceditor_init(): void
    {
        echo '
<script>
(function(){
    function init(){
        if (!window.jQuery || !jQuery.fn || !jQuery.fn.sceditor) return;
        var $ = jQuery;

        // выбираем ТОЛЬКО наши поля
        var $areas = $(\'textarea[name="body"], textarea[name="description"]\');

        $areas.each(function(i){
            var $ta = $(this);
            if ($ta.data("sceditor-initialized")) return;

            try {
                $ta.sceditorBBCode({
                    style: "../jscripts/sceditor/themes/content/default.min.css",
                    toolbar: "bold,italic,underline,strike|left,center,right|bulletlist,orderedlist|quote,code|link,unlink|source",
                    resizeEnabled: true,
                    emoticonsEnabled: false
                });

                $ta.data("sceditor-initialized", true);
            } catch(e) {
                console && console.error && console.error("SCEditor init error:", e);
            }
        });
    }

    if (document.readyState === "loading") {
        document.addEventListener("DOMContentLoaded", init);
    } else {
        init();
    }
})();
</script>';
    }
}
