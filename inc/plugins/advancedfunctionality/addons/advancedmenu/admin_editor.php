<?php
/** ACP tree projection and validation over the existing menu tables. */
if (!defined('IN_MYBB') || !defined('AF_ADDONS')) { die('No direct access'); }

function af_am_editor_catalog(): array
{
    $items = [];
    foreach (af_menu_configured_registry() as $item) {
        if (af_menu_is_logout($item)) continue;
        $id = 'p:'.af_menu_provider_identity($item);
        $items[$id] = ['id'=>$id, 'kind'=>'provider', 'key'=>$item['key'], 'source'=>$item['source_addon'],
            'label'=>$item['label'], 'icon'=>$item['icon'], 'enabled'=>(int)$item['enabled'],
            'type'=>$item['type'], 'url'=>(string)($item['action']['url'] ?? ''),
            'visibility'=>'Определяется провайдером', 'action'=>array_map(static fn($value) => is_scalar($value) || $value === null ? $value : '(provider callback)', (array)$item['action']),
            'allowed'=>$item['allowed_containers'], 'container'=>$item['container'], 'section'=>$item['section'],
            'sortorder'=>(int)$item['sortorder'], 'defaults'=>af_menu_collect_registry()[$item['key']]];
        // Callable providers stay on the server, never serialize callback objects.
        $items[$id]['defaults'] = array_intersect_key($items[$id]['defaults'], array_flip([
            'label','icon','default_container','section','default_sortorder'
        ]));
    }
    foreach (af_advancedmenu_get_items(true) as $item) {
        if (af_menu_is_logout($item)) continue;
        $id = 'c:'.(int)$item['id'];
        $items[$id] = ['id'=>$id, 'kind'=>'custom', 'key'=>$item['slug'], 'source'=>'AdvancedMenu', 'type'=>'link',
            'label'=>$item['title'], 'icon'=>$item['icon'], 'enabled'=>(int)$item['enabled'], 'url'=>$item['url'],
            'hint'=>$item['hint'], 'visibility'=>$item['visibility'], 'allowed'=>array_keys(af_menu_containers()),
            'container'=>af_menu_normalize_container((string)($item['container'] ?? $item['location'])),
            'section'=>af_menu_normalize_section((string)($item['section'] ?? 'links')), 'sortorder'=>(int)$item['sort_order']];
    }
    return $items;
}

function af_am_editor_snapshot(): array
{
    $items = af_am_editor_catalog();
    $sections = af_menu_section_config();
    $tree = array_fill_keys(array_keys(af_menu_containers()), []);
    foreach ($sections as $key => &$section) {
        $section['container'] = $section['container'] ?? 'user_drawer';
        $tree[$section['container']][] = ['kind'=>'section', 'id'=>$key, 'children'=>[]];
    }
    unset($section);
    foreach ($items as $id => $item) {
        $key = $item['section'];
        $explicit = af_menu_section_config()[$key]['container'] ?? null;
        $parent = isset($sections[$key]) && ($explicit !== null || $item['container'] === 'user_drawer') ? $key : null;
        $container = $parent !== null ? $sections[$parent]['container'] : $item['container'];
        if ($parent !== null) {
            foreach ($tree[$container] as &$node) if ($node['kind'] === 'section' && $node['id'] === $parent) $node['children'][] = $id;
            unset($node);
        } else $tree[$container][] = ['kind'=>'item', 'id'=>$id];
    }
    foreach ($tree as &$nodes) {
        usort($nodes, static function ($a, $b) use ($items, $sections) {
            $sort = static fn($n) => $n['kind'] === 'section' ? $sections[$n['id']]['sortorder'] : $items[$n['id']]['sortorder'];
            return [$sort($a), $a['id']] <=> [$sort($b), $b['id']];
        });
        foreach ($nodes as &$node) if ($node['kind'] === 'section') {
            usort($node['children'], static fn($a,$b) => [$items[$a]['sortorder'],$a] <=> [$items[$b]['sortorder'],$b]);
        }
        unset($node);
    }
    unset($nodes);
    // Include all persisted rows, even unavailable providers, for stale-editor detection.
    $revision = hash('sha256', serialize([$items, af_menu_section_config(), af_menu_get_overrides(), af_advancedmenu_get_items(true)]));
    return ['items'=>$items, 'sections'=>$sections, 'tree'=>$tree, 'deleted'=>[], 'revision'=>$revision];
}

function af_am_editor_text(array $row, string $field, int $max, bool $required = false): string
{
    if (!isset($row[$field]) || !is_string($row[$field])) throw new InvalidArgumentException('Некорректное поле: '.$field);
    $value = trim($row[$field]);
    if (($required && $value === '') || (function_exists('my_strlen') ? my_strlen($value) : (function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value))) > $max) throw new InvalidArgumentException('Проверьте поле: '.$field);
    return $value;
}

function af_am_editor_visibility(string $value): string
{
    if (!preg_match('~^(?:|all|guests?|users?|members|groups:[1-9][0-9]*(?:,[1-9][0-9]*)*)$~D', $value)) {
        throw new InvalidArgumentException('Доступ: используйте all, guests, users или groups:1,4.');
    }
    return $value;
}

/** Validate the entire request before the first write; the client cannot edit provider contracts. */
function af_am_editor_validate(array $data, array $current): array
{
    if (!is_string($data['revision'] ?? null) || !hash_equals($current['revision'], $data['revision'])) {
        throw new InvalidArgumentException('Меню изменилось на сервере. Ваши изменения сохранены на экране; откройте актуальную версию в другой вкладке для сравнения.');
    }
    foreach (['tree','items','sections','deleted'] as $field) if (!isset($data[$field]) || !is_array($data[$field])) throw new InvalidArgumentException('Повреждена структура меню.');
    $containers = af_menu_containers();
    if (array_diff(array_keys($data['tree']), array_keys($containers)) || count($data['tree']) !== 3) throw new InvalidArgumentException('Ожидаются три области меню.');
    $deleted = [];
    foreach ($data['deleted'] as $id) {
        if (!is_string($id) || !isset($current['items'][$id]) || $current['items'][$id]['kind'] !== 'custom' || isset($deleted[$id])) throw new InvalidArgumentException('Удалять можно только существующие пользовательские пункты.');
        $deleted[$id] = true;
    }
    $sections = $items = $seen = [];
    $readItem = static function ($id, string $container, string $section, int $order) use (&$items, &$seen, $deleted, $data, $current): void {
        if (!is_string($id) || isset($seen[$id]) || isset($deleted[$id]) || !is_array($data['items'][$id] ?? null)) throw new InvalidArgumentException('Дубликат или неизвестный пункт меню.');
        $seen[$id] = true;
        $old = $current['items'][$id] ?? null;
        if (!$old && !preg_match('~^n:[a-z0-9_-]{1,60}$~D', $id)) throw new InvalidArgumentException('Неизвестный идентификатор пункта.');
        $row = $data['items'][$id];
        $provider = $old && $old['kind'] === 'provider';
        if ($provider && !in_array($container, $old['allowed'], true)) throw new InvalidArgumentException('Провайдер запрещает эту область для «'.$old['label'].'».');
        $reset = $provider && !empty($row['reset']);
        $save = ['id'=>$id, 'kind'=>$provider ? 'provider' : 'custom', 'container'=>$container, 'section'=>$section, 'sortorder'=>$order,
            'label'=>$reset ? $old['defaults']['label'] : af_am_editor_text($row,'label',255,true),
            'icon'=>$reset ? $old['defaults']['icon'] : af_am_editor_text($row,'icon',255),
            'enabled'=>$reset ? 1 : (empty($row['enabled']) ? 0 : 1), 'reset'=>$reset];
        if ($provider) {
            $save['source'] = $old['source']; $save['key'] = $old['key'];
        } else {
            $save['key'] = af_am_editor_text($row,'key',64,true);
            if (!preg_match('~^[a-z][a-z0-9_]{2,63}$~D', $save['key'])) throw new InvalidArgumentException('Key: 3–64 латинских символа, цифры и _, начиная с буквы.');
            $save['url'] = af_am_editor_text($row,'url',500,true);
            if (preg_match('~^(?:javascript|data|vbscript):~i', $save['url'])) throw new InvalidArgumentException('Недопустимая схема URL.');
            $save['hint'] = strip_tags(af_am_editor_text($row,'hint',255));
            $visibility = af_am_editor_text($row,'visibility',255);
            // Preserve unknown legacy access rules unchanged; validate edited rules.
            $save['visibility'] = $old && $visibility === $old['visibility'] ? $visibility : af_am_editor_visibility($visibility);
            if ($save['icon'] !== '' && strpbrk($save['icon'], '<>') !== false) $save['icon'] = af_advancedmenu_sanitize_icon_html($save['icon']);
        }
        $items[$id] = $save;
    };
    foreach ($data['tree'] as $container => $nodes) {
        if (!is_array($nodes) || !array_is_list($nodes)) throw new InvalidArgumentException('Некорректная область меню.');
        foreach ($nodes as $index => $node) {
            if (!is_array($node)) throw new InvalidArgumentException('Некорректный узел меню.');
            if (($node['kind'] ?? '') === 'item') {
                if (isset($node['children'])) throw new InvalidArgumentException('У пункта не может быть дочерних элементов.');
                $readItem($node['id'] ?? null, $container, 'none', ($index+1)*10);
                continue;
            }
            $id = $node['id'] ?? null;
            if (($node['kind'] ?? '') !== 'section' || !is_string($id) || isset($sections[$id])
                || (!isset($current['sections'][$id]) && !preg_match('~^s_[a-z0-9_]{1,22}$~D',$id))
                || !is_array($data['sections'][$id] ?? null) || !is_array($node['children'] ?? null) || !array_is_list($node['children'])) throw new InvalidArgumentException('Неизвестная или вложенная секция.');
            $row = $data['sections'][$id];
            $sections[$id] = ['container'=>$container, 'label'=>af_am_editor_text($row,'label',255,true),
                'icon'=>preg_replace('~[^a-z0-9 _-]~i','',af_am_editor_text($row,'icon',255)),
                'sortorder'=>($index+1)*10, 'enabled'=>empty($row['enabled']) ? 0 : 1,
                'visibility'=>af_am_editor_visibility(af_am_editor_text($row,'visibility',255))];
            foreach ($node['children'] as $childIndex => $child) $readItem($child,$container,$id,($childIndex+1)*10);
        }
    }
    foreach ($current['sections'] as $id => $row) if (!isset($sections[$id])) throw new InvalidArgumentException('Секция потеряна. Перенесите её целиком или выключите.');
    foreach ($current['items'] as $id => $row) if (!isset($seen[$id]) && !isset($deleted[$id])) throw new InvalidArgumentException('Пункт потерян. Обновление отменено.');
    foreach ($data['items'] as $id => $row) if (!isset($seen[$id]) && !isset($deleted[$id])) throw new InvalidArgumentException('Найдены пункты без родителя.');
    if (count($sections) !== count($data['sections'])) throw new InvalidArgumentException('Найдены секции без родителя.');
    // Match the historical unique index (location, slug), including protected logout copies.
    $slugs = [];
    foreach (af_advancedmenu_get_items(true) as $row) if (af_menu_is_logout($row)) $slugs[$row['location'].'::'.$row['slug']] = true;
    foreach ($items as $row) if ($row['kind'] === 'custom') {
        $identity = ($row['container'] === 'user_drawer' ? 'panel' : 'top').'::'.$row['key'];
        if (isset($slugs[$identity])) throw new InvalidArgumentException('Повторяющийся Key пользовательского пункта: '.$row['key']);
        $slugs[$identity] = true;
    }
    return ['items'=>$items, 'sections'=>$sections, 'deleted'=>array_keys($deleted)];
}

function af_am_editor_apply(array $plan): void
{
    global $db, $mybb;
    $db->write_query('START TRANSACTION');
    try {
        foreach ($plan['deleted'] as $id) $db->delete_query(AF_AM_TABLE_ITEMS,"id='".(int)substr($id,2)."'");
        // Free legacy unique locations before moving/swapping custom items.
        foreach ($plan['items'] as $id => $row) if ($row['kind'] === 'custom' && str_starts_with($id,'c:')) {
            $db->update_query(AF_AM_TABLE_ITEMS,['location'=>'m'.base_convert(substr($id,2),10,36)],"id='".(int)substr($id,2)."'");
        }
        foreach ($plan['items'] as $id => $row) {
            if ($row['kind'] === 'custom') {
                $save = ['container'=>$row['container'], 'location'=>$row['container']==='user_drawer'?'panel':'top',
                    'section'=>$row['section'], 'sort_order'=>$row['sortorder'], 'title'=>$row['label'], 'slug'=>$row['key'],
                    'icon'=>$row['icon'], 'url'=>$row['url'], 'hint'=>$row['hint'], 'visibility'=>$row['visibility'],
                    'enabled'=>$row['enabled'], 'updated_at'=>TIME_NOW];
                if (str_starts_with($id,'c:')) $db->update_query(AF_AM_TABLE_ITEMS,$save,"id='".(int)substr($id,2)."'");
                else $db->insert_query(AF_AM_TABLE_ITEMS,$save+['created_at'=>TIME_NOW]);
            } else {
                $source = $db->escape_string($row['source']); $key = $db->escape_string($row['key']);
                $where = "source_addon='{$source}' AND item_key='{$key}'";
                $existing = $db->fetch_array($db->simple_select(AF_AM_TABLE_OVERRIDES,'*',$where,['limit'=>1]));
                $save = ['container'=>$row['container'],'section'=>$row['section'],'sortorder'=>$row['sortorder'],
                    'enabled'=>$row['enabled'],'label_override'=>$row['reset'] ? null : $row['label'],
                    'icon_override'=>$row['reset'] ? null : $row['icon'],
                    'updated_at'=>max(TIME_NOW,(int)($existing['created_at'] ?? TIME_NOW)+1)];
                if ($existing) $db->update_query(AF_AM_TABLE_OVERRIDES,$save,$where);
                else $db->insert_query(AF_AM_TABLE_OVERRIDES,$save+['source_addon'=>$row['source'],'item_key'=>$row['key'],'created_at'=>TIME_NOW]);
            }
        }
        $encoded = json_encode($plan['sections'],JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $db->update_query('settings',['value'=>$encoded],"name='af_advancedmenu_sections'");
        $db->write_query('COMMIT');
    } catch (Throwable $error) {
        $db->write_query('ROLLBACK');
        throw $error;
    }
    $mybb->settings['af_advancedmenu_sections'] = $encoded;
    rebuild_settings();
    af_advancedmenu_rebuild_cache();
}
