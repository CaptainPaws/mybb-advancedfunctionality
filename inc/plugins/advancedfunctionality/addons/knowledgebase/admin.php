<?php
if (!defined('IN_MYBB')) { die('No direct access'); }

class AF_Admin_KnowledgeBase
{
    public static function dispatch(): void
    {
        global $db;

        $tab = isset($_GET['tab']) ? (string)$_GET['tab'] : 'types';
        $act = isset($_GET['act']) ? (string)$_GET['act'] : '';

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            verify_post_check((string)($_POST['my_post_key'] ?? ''));
            if ($tab === 'types') {
                self::saveType();
            } elseif ($tab === 'kinds') {
                self::saveKind();
            }
        }

        if ($act === 'delete' && $tab === 'types') {
            self::deleteType();
        }
        if ($act === 'delete' && $tab === 'kinds') {
            self::deleteKind();
        }

        echo '<div class="group"><h3>Knowledge Base</h3>';
        echo '<div class="border_wrapper" style="padding:12px;">';
        echo '<p><a class="button" href="index.php?module=config-advancedfunctionality-knowledgebase&tab=types">KB → Типы</a> ';
        echo '<a class="button" href="index.php?module=config-advancedfunctionality-knowledgebase&tab=kinds">KB → Подтипы предметов</a></p>';

        if ($tab === 'kinds') {
            self::renderKinds($act);
        } else {
            self::renderTypes($act);
        }

        echo '</div></div>';
    }

    private static function renderTypes(string $act): void
    {
        global $db, $mybb;

        if ($act === 'edit' || $act === 'new') {
            $id = (int)($_GET['id'] ?? 0);
            $row = ['id'=>0,'type_key'=>'','title_ru'=>'','title_en'=>'','desc_ru'=>'','desc_en'=>'','rules_schema'=>'af_kb.rules.v1','ui_schema_json'=>'{}','is_active'=>1,'sortorder'=>0];
            if ($id > 0) {
                $found = $db->fetch_array($db->simple_select('af_kb_types', '*', 'id='.$id, ['limit'=>1]));
                if ($found) {
                    $row = array_merge($row, $found);
                    if (empty($row['type_key'])) { $row['type_key'] = (string)($row['type'] ?? ''); }
                }
            }
            echo '<h4>'.($id ? 'Редактировать тип' : 'Добавить тип').'</h4>';
            echo '<form method="post"><input type="hidden" name="my_post_key" value="'.htmlspecialchars_uni($mybb->post_code).'" />';
            echo '<input type="hidden" name="id" value="'.(int)$row['id'].'" />';
            echo '<p>type_key: <input type="text" name="type_key" value="'.htmlspecialchars_uni((string)$row['type_key']).'" required /></p>';
            echo '<p>title_ru: <input type="text" name="title_ru" value="'.htmlspecialchars_uni((string)$row['title_ru']).'" required /></p>';
            echo '<p>title_en: <input type="text" name="title_en" value="'.htmlspecialchars_uni((string)$row['title_en']).'" required /></p>';
            echo '<p>rules_schema: <input type="text" name="rules_schema" value="'.htmlspecialchars_uni((string)$row['rules_schema']).'" /></p>';
            echo '<p>sortorder: <input type="number" name="sortorder" value="'.(int)$row['sortorder'].'" /></p>';
            echo '<p>active: <input type="checkbox" name="is_active" value="1" '.(!empty($row['is_active']) ? 'checked' : '').' /></p>';
            echo '<p>desc_ru:<br><textarea name="desc_ru" rows="3" style="width:100%;">'.htmlspecialchars_uni((string)$row['desc_ru']).'</textarea></p>';
            echo '<p>desc_en:<br><textarea name="desc_en" rows="3" style="width:100%;">'.htmlspecialchars_uni((string)$row['desc_en']).'</textarea></p>';
            echo '<p>ui_schema_json:<br><textarea name="ui_schema_json" rows="18" style="width:100%;">'.htmlspecialchars_uni((string)$row['ui_schema_json']).'</textarea></p>';
            echo '<p><button class="button button_yes" type="submit">Сохранить</button></p></form>';
            return;
        }

        echo '<p><a class="button" href="index.php?module=config-advancedfunctionality-knowledgebase&tab=types&act=new">Добавить тип</a></p>';
        echo '<table class="table"><tr><th>type_key</th><th>RU</th><th>EN</th><th>schema</th><th>active</th><th>sort</th><th></th></tr>';
        $q = $db->simple_select('af_kb_types', '*', '', ['order_by' => 'sortorder, type']);
        while ($row = $db->fetch_array($q)) {
            $key = (string)($row['type_key'] ?: $row['type']);
            echo '<tr>';
            echo '<td>'.htmlspecialchars_uni($key).'</td>';
            echo '<td>'.htmlspecialchars_uni((string)$row['title_ru']).'</td>';
            echo '<td>'.htmlspecialchars_uni((string)$row['title_en']).'</td>';
            echo '<td>'.htmlspecialchars_uni((string)($row['rules_schema'] ?: 'af_kb.rules.v1')).'</td>';
            echo '<td>'.(!empty($row['is_active']) ? '1' : '0').'</td>';
            echo '<td>'.(int)$row['sortorder'].'</td>';
            echo '<td><a href="index.php?module=config-advancedfunctionality-knowledgebase&tab=types&act=edit&id='.(int)$row['id'].'">edit</a> | '
                .'<a href="index.php?module=config-advancedfunctionality-knowledgebase&tab=types&act=delete&id='.(int)$row['id'].'&my_post_key='.htmlspecialchars_uni($mybb->post_code).'">delete</a></td>';
            echo '</tr>';
        }
        echo '</table>';
    }

    private static function renderKinds(string $act): void
    {
        global $db, $mybb;

        if ($act === 'edit' || $act === 'new') {
            $id = (int)($_GET['id'] ?? 0);
            $row = ['id'=>0,'kind_key'=>'','title_ru'=>'','title_en'=>'','desc_ru'=>'','desc_en'=>'','ui_schema_json'=>'{}','is_active'=>1,'sortorder'=>0];
            if ($id > 0) {
                $found = $db->fetch_array($db->simple_select('af_kb_item_kinds', '*', 'id='.$id, ['limit'=>1]));
                if ($found) { $row = array_merge($row, $found); }
            }
            echo '<h4>'.($id ? 'Редактировать подтип' : 'Добавить подтип').'</h4>';
            echo '<form method="post"><input type="hidden" name="my_post_key" value="'.htmlspecialchars_uni($mybb->post_code).'" />';
            echo '<input type="hidden" name="id" value="'.(int)$row['id'].'" />';
            echo '<p>kind_key: <input type="text" name="kind_key" value="'.htmlspecialchars_uni((string)$row['kind_key']).'" required /></p>';
            echo '<p>title_ru: <input type="text" name="title_ru" value="'.htmlspecialchars_uni((string)$row['title_ru']).'" required /></p>';
            echo '<p>title_en: <input type="text" name="title_en" value="'.htmlspecialchars_uni((string)$row['title_en']).'" required /></p>';
            echo '<p>sortorder: <input type="number" name="sortorder" value="'.(int)$row['sortorder'].'" /></p>';
            echo '<p>active: <input type="checkbox" name="is_active" value="1" '.(!empty($row['is_active']) ? 'checked' : '').' /></p>';
            echo '<p>ui_schema_json:<br><textarea name="ui_schema_json" rows="14" style="width:100%;">'.htmlspecialchars_uni((string)$row['ui_schema_json']).'</textarea></p>';
            echo '<p><button class="button button_yes" type="submit">Сохранить</button></p></form>';
            return;
        }

        echo '<p><a class="button" href="index.php?module=config-advancedfunctionality-knowledgebase&tab=kinds&act=new">Добавить подтип</a></p>';
        echo '<table class="table"><tr><th>kind_key</th><th>RU</th><th>EN</th><th>active</th><th>sort</th><th></th></tr>';
        $q = $db->simple_select('af_kb_item_kinds', '*', '', ['order_by' => 'sortorder, kind_key']);
        while ($row = $db->fetch_array($q)) {
            echo '<tr><td>'.htmlspecialchars_uni((string)$row['kind_key']).'</td><td>'.htmlspecialchars_uni((string)$row['title_ru']).'</td><td>'.htmlspecialchars_uni((string)$row['title_en']).'</td><td>'.(!empty($row['is_active']) ? '1' : '0').'</td><td>'.(int)$row['sortorder'].'</td><td><a href="index.php?module=config-advancedfunctionality-knowledgebase&tab=kinds&act=edit&id='.(int)$row['id'].'">edit</a> | <a href="index.php?module=config-advancedfunctionality-knowledgebase&tab=kinds&act=delete&id='.(int)$row['id'].'&my_post_key='.htmlspecialchars_uni($mybb->post_code).'">delete</a></td></tr>';
        }
        echo '</table>';
    }

    private static function saveType(): void
    {
        global $db;
        $id = (int)($_POST['id'] ?? 0);
        $key = preg_replace('~[^a-z0-9_]+~', '', strtolower((string)($_POST['type_key'] ?? '')));
        $schema = (string)($_POST['ui_schema_json'] ?? '{}');
        json_decode($schema, true);
        if (json_last_error() !== JSON_ERROR_NONE || $key === '') {
            flash_message('Ошибка JSON или type_key.', 'error');
            admin_redirect('index.php?module=config-advancedfunctionality-knowledgebase&tab=types'.($id ? '&act=edit&id='.$id : '&act=new'));
        }

        $data = [
            'type' => $db->escape_string($key),
            'type_key' => $db->escape_string($key),
            'title_ru' => $db->escape_string((string)($_POST['title_ru'] ?? '')),
            'title_en' => $db->escape_string((string)($_POST['title_en'] ?? '')),
            'desc_ru' => $db->escape_string((string)($_POST['desc_ru'] ?? '')),
            'desc_en' => $db->escape_string((string)($_POST['desc_en'] ?? '')),
            'rules_schema' => $db->escape_string((string)($_POST['rules_schema'] ?? 'af_kb.rules.v1')),
            'ui_schema_json' => $db->escape_string($schema),
            'is_active' => !empty($_POST['is_active']) ? 1 : 0,
            'active' => !empty($_POST['is_active']) ? 1 : 0,
            'sortorder' => (int)($_POST['sortorder'] ?? 0),
            'updated_at' => TIME_NOW,
        ];
        if ($id > 0) { $db->update_query('af_kb_types', $data, 'id='.$id); } else { $db->insert_query('af_kb_types', $data); }
        flash_message('Тип сохранён.', 'success');
        admin_redirect('index.php?module=config-advancedfunctionality-knowledgebase&tab=types');
    }

    private static function saveKind(): void
    {
        global $db;
        $id = (int)($_POST['id'] ?? 0);
        $key = preg_replace('~[^a-z0-9_]+~', '', strtolower((string)($_POST['kind_key'] ?? '')));
        $schema = (string)($_POST['ui_schema_json'] ?? '{}');
        json_decode($schema, true);
        if (json_last_error() !== JSON_ERROR_NONE || $key === '') {
            flash_message('Ошибка JSON или kind_key.', 'error');
            admin_redirect('index.php?module=config-advancedfunctionality-knowledgebase&tab=kinds'.($id ? '&act=edit&id='.$id : '&act=new'));
        }

        $data = [
            'kind_key' => $db->escape_string($key),
            'title_ru' => $db->escape_string((string)($_POST['title_ru'] ?? '')),
            'title_en' => $db->escape_string((string)($_POST['title_en'] ?? '')),
            'ui_schema_json' => $db->escape_string($schema),
            'is_active' => !empty($_POST['is_active']) ? 1 : 0,
            'sortorder' => (int)($_POST['sortorder'] ?? 0),
            'updated_at' => TIME_NOW,
        ];
        if ($id > 0) { $db->update_query('af_kb_item_kinds', $data, 'id='.$id); } else { $db->insert_query('af_kb_item_kinds', $data); }
        flash_message('Подтип сохранён.', 'success');
        admin_redirect('index.php?module=config-advancedfunctionality-knowledgebase&tab=kinds');
    }

    private static function deleteType(): void
    {
        global $db;
        verify_post_check((string)($_GET['my_post_key'] ?? ''));
        $id = (int)($_GET['id'] ?? 0);
        $row = $db->fetch_array($db->simple_select('af_kb_types', '*', 'id='.$id, ['limit' => 1]));
        if (!$row) { admin_redirect('index.php?module=config-advancedfunctionality-knowledgebase&tab=types'); }
        $type = (string)($row['type_key'] ?: $row['type']);
        $cnt = (int)$db->fetch_field($db->simple_select('af_kb_entries', 'COUNT(*) AS cnt', "type='".$db->escape_string($type)."'"), 'cnt');
        if ($cnt > 0) {
            flash_message('Нельзя удалить: есть записи этого типа.', 'error');
            admin_redirect('index.php?module=config-advancedfunctionality-knowledgebase&tab=types');
        }
        $db->delete_query('af_kb_types', 'id='.$id);
        flash_message('Тип удалён.', 'success');
        admin_redirect('index.php?module=config-advancedfunctionality-knowledgebase&tab=types');
    }

    private static function deleteKind(): void
    {
        global $db;
        verify_post_check((string)($_GET['my_post_key'] ?? ''));
        $id = (int)($_GET['id'] ?? 0);
        $row = $db->fetch_array($db->simple_select('af_kb_item_kinds', '*', 'id='.$id, ['limit' => 1]));
        if (!$row) { admin_redirect('index.php?module=config-advancedfunctionality-knowledgebase&tab=kinds'); }
        $cnt = (int)$db->fetch_field($db->simple_select('af_kb_entries', 'COUNT(*) AS cnt', "item_kind='".$db->escape_string((string)$row['kind_key'])."'"), 'cnt');
        if ($cnt > 0) {
            flash_message('Нельзя удалить: есть item-записи с этим kind.', 'error');
            admin_redirect('index.php?module=config-advancedfunctionality-knowledgebase&tab=kinds');
        }
        $db->delete_query('af_kb_item_kinds', 'id='.$id);
        flash_message('Подтип удалён.', 'success');
        admin_redirect('index.php?module=config-advancedfunctionality-knowledgebase&tab=kinds');
    }
}
