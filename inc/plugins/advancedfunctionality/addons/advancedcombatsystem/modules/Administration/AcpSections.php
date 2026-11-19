<?php
if(!defined('IN_MYBB')||!defined('IN_ADMINCP')||!IN_ADMINCP){die('No direct access');}
require_once __DIR__.'/AcpReadModel.php';
require_once __DIR__.'/../Presentation/SafeError.php';

final class AF_ACS_AcpSections
{
    private static function esc(mixed $v):string{return htmlspecialchars_uni((string)$v);}
    public static function url(string $section,array $args=[]):string{return 'index.php?'.http_build_query(['module'=>'advancedfunctionality','af_view'=>'advancedcombatsystem','acs_section'=>$section]+$args);}
    private static function hidden(array $args):string{$html='';foreach($args as $key=>$value)$html.='<input type="hidden" name="'.self::esc($key).'" value="'.self::esc($value).'">';return $html;}
    private static function filters(string $section):string{return '<form method="get" action="index.php">'.self::hidden(['module'=>'advancedfunctionality','af_view'=>'advancedcombatsystem','acs_section'=>$section]);}
    private static function failure(Throwable $e, string $operation = 'acp_inspect'): void
    {
        $error = AF_ACS_SafeError::from($e, $operation);
        echo '<p class="error">'.self::esc('Проверка ACP недоступна. Проверьте ввод, доступ к теме и состояние схемы. '
            .$error['code'].' · error_id='.$error['error_id']).'</p>';
    }
    public static function policyDescription(string $policy):string
    {
        return [
            'AF_ACP_INDEX'=>'Штатное административное право AdvancedFunctionality.',
            'NATIVE_THREAD_AND_MEMBERSHIP'=>'Native доступ к теме и действующее членство сессии.',
            'NATIVE_THREAD_AND_OWNER_GM'=>'Native доступ и сохранённая роль owner/GM.',
            'SESSION_MEMBER_AND_VERIFIED_OWNER'=>'Членство сессии и подтверждённое владение персонажем.',
            'OWNER_GM_CONFIRM_REASON'=>'Owner/GM, явное подтверждение и причина.',
            'OWNER_GM_BOUNDARIES_OBLIGATIONS'=>'Owner/GM; границы хода и незавершённые обязательства проверяет сервер.',
            'OWNER_GM_ROSTER_CLOSED'=>'Owner/GM; roster должен быть закрыт сервером.',
            'OWNER_CONSENT_OR_EXPLICIT_REPLACEMENT'=>'Согласие владельца либо отдельная разрешённая замена.',
            'OWNER_DELEGATE_OR_EXPLICIT_REPLACEMENT'=>'Владелец, делегат либо отдельная разрешённая замена.',
            'NATIVE_THREAD_AND_OWNER_GM_NPC_ONLY'=>'Native доступ и owner/GM; только NPC.',
            'CURRENT_ENTITY_ASSIGNMENT'=>'Текущее сохранённое назначение контроллера сущности.',
            'RESERVED_DENY'=>'Универсальная команда запрещена. Только отдельные утверждённые контракты.',
            'AF_ACP_INDEX_SESSION_ONLY'=>'Право AF ACP; только передача владельца сессии по существующему контракту.',
            'NATIVE_SESSION_GM_CLOSED'=>'Native доступ и GM закрытой сессии; остальные guards сохраняются.',
        ][$policy]??'Точный серверный контракт; дополнительные guards не отменяются.';
    }
    private const EDITABLE_SETTINGS = ['af_acs_session_gm_groups', 'af_acs_default_member_role', 'af_acs_archive_export_groups'];

    private static function settingRows(): array
    {
        global $db;
        $names = [...self::EDITABLE_SETTINGS, 'af_acs_archive_cursor_key'];
        $query = $db->write_query('SELECT sid,name,value FROM '.TABLE_PREFIX."settings WHERE name IN ('".implode("','", $names)."')");
        $rows = [];
        while ($row = $db->fetch_array($query)) {
            if (isset($rows[$row['name']])) { throw new RuntimeException('ACS_SETTINGS_DUPLICATE'); }
            $rows[$row['name']] = $row;
        }
        return $rows;
    }

    private static function settingGroups(): array
    {
        global $db;
        $groups = [];
        $query = $db->write_query('SELECT gid,title FROM '.TABLE_PREFIX.'usergroups ORDER BY title,gid');
        while ($row = $db->fetch_array($query)) { $groups[(int)$row['gid']] = $row['title']; }
        return $groups;
    }

    private static function groupSetting(string $name, array $groups): string
    {
        global $mybb;
        $input = $mybb->input[$name] ?? [];
        if (!is_array($input) || count($input) > count($groups)) { throw new RuntimeException('ACS_SETTINGS_INVALID_GROUP'); }
        $selected = [];
        foreach ($input as $id) {
            if ((!is_string($id) && !is_int($id)) || !preg_match('/\A[1-9][0-9]*\z/D', (string)$id)
                || !array_key_exists((string)$id, $groups)) {
                throw new RuntimeException('ACS_SETTINGS_INVALID_GROUP');
            }
            $selected[(int)$id] = (int)$id;
        }
        sort($selected, SORT_NUMERIC);
        return implode(',', $selected);
    }

    /** Called only through the ACP dispatcher after schema-management permission and CSRF. */
    public static function saveSettings(): void
    {
        global $db, $mybb;
        $rows = self::settingRows();
        $groups = self::settingGroups();
        $role = $mybb->get_input('af_acs_default_member_role');
        if (!in_array($role, ['observer', 'player'], true)) { throw new RuntimeException('ACS_SETTINGS_INVALID_ROLE'); }
        $values = [
            'af_acs_session_gm_groups'=>self::groupSetting('af_acs_session_gm_groups', $groups),
            'af_acs_default_member_role'=>$role,
            'af_acs_archive_export_groups'=>self::groupSetting('af_acs_archive_export_groups', $groups),
        ];
        $cases = $ids = [];
        foreach ($values as $name => $value) {
            if (!isset($rows[$name])) { throw new RuntimeException('ACS_SETTINGS_NOT_INITIALIZED'); }
            $sid = (int)$rows[$name]['sid'];
            $ids[] = $sid;
            $cases[] = "WHEN $sid THEN '".$db->escape_string($value)."'";
        }
        // One native settings-table update after all inputs have been verified.
        if (!$db->write_query('UPDATE '.TABLE_PREFIX.'settings SET value=CASE sid '.implode(' ', $cases)
            .' END WHERE sid IN ('.implode(',', $ids).')', true)) {
            throw new RuntimeException('ACS_SETTINGS_SAVE_FAILED');
        }
        af_rebuild_and_reload_settings();
        if (function_exists('log_admin_action')) { log_admin_action('acs_save_settings', implode(', ', array_keys($values))); }
    }

    public static function settings(): void
    {
        global $mybb;
        echo '<h3>Поддерживаемые глобальные настройки</h3>';
        try { $rows = self::settingRows(); }
        catch (Throwable $error) {
            echo '<p class="error">Не удалось прочитать настройки ACS. Проверьте диагностику установленных настроек.</p>';
            return;
        }
        $form = '<form method="post" action="'.self::esc(self::url('main')).'">'
            .self::hidden(['my_post_key'=>$mybb->post_code]);
        $missing = array_diff(self::EDITABLE_SETTINGS, array_keys($rows));
        if ($missing || !isset($rows['af_acs_archive_cursor_key'])) {
            echo $form.self::hidden(['acs_command'=>'initialize_settings'])
                .'<p>Некоторые настройки ACS отсутствуют. Восстановление добавит недостающие значения.</p>'
                .'<button type="submit" class="submit_button">Восстановить настройки ACS</button></form>';
        }
        if ($missing) { return; }

        $groups = self::settingGroups();
        echo $form.self::hidden(['acs_command'=>'save_settings']);
        $table = new Table;
        foreach (['Параметр', 'Значение', 'Область действия'] as $label) { $table->construct_header($label); }
        foreach ([
            'af_acs_session_gm_groups'=>['Кто может создавать боевые сессии ACS', 'Пустой выбор оставляет право у подтверждённых администраторов.'],
            'af_acs_default_member_role'=>['Роль участника по умолчанию', 'Применяется к новым сессиям.'],
            'af_acs_archive_export_groups'=>['Кто может использовать расширенный экспорт', 'Сохраняются требования текущей роли GM, доступа к теме и политики экспорта. Пустой выбор запрещает экспорт.'],
        ] as $name => [$label, $help]) {
            $value = $rows[$name]['value'];
            $control = '<select id="'.$name.'" name="'.$name.($name === 'af_acs_default_member_role' ? '">' : '[]" multiple size="7">');
            $options = $name === 'af_acs_default_member_role' ? ['observer'=>'Наблюдатель', 'player'=>'Игрок'] : $groups;
            $selected = $name === 'af_acs_default_member_role' ? [$value] : array_map('trim', explode(',', $value));
            foreach ($options as $key => $title) {
                $control .= '<option value="'.self::esc($key).'"'.(in_array((string)$key, $selected, true) ? ' selected' : '').'>'.self::esc($title).'</option>';
            }
            $table->construct_cell('<label for="'.$name.'">'.self::esc($label).'</label>');
            $table->construct_cell($control.'</select>');
            $table->construct_cell(self::esc($help));
            $table->construct_row();
        }
        $table->output('Настройки ACS');
        echo '<p><button type="submit" class="submit_button">Сохранить настройки</button></p></form>';
    }
    public static function sessions():void
    {
        global $db,$mybb;
        if($mybb->request_method==='post'){echo '<p class="error">Реестр только для чтения. Игровые команды выполняются в разрешённой GM-панели с её CSRF, CAS, reason и audit.</p>';return;}
        $filters=[];foreach(['session_id','tid','owner_uid','status'] as $key)$filters[$key]=$mybb->get_input('acs_filter_'.$key);
        echo '<h3>Реестр сессий · только чтение</h3>'.self::filters('sessions');
        foreach(['session_id'=>'Сессия #','tid'=>'Тема TID','owner_uid'=>'Владелец UID'] as $key=>$label)echo '<label>'.$label.' <input name="acs_filter_'.$key.'" inputmode="numeric" value="'.self::esc($filters[$key]).'"></label> ';
        echo '<label>Состояние <select name="acs_filter_status"><option value="">Все доступные</option>';foreach(['active'=>'Активна','pending_close'=>'Ожидает закрытия','closed'=>'Закрыта','archived'=>'Архив'] as $key=>$label)echo '<option value="'.$key.'"'.($filters['status']===$key?' selected':'').'>'.$label.'</option>';
        echo '</select></label><button>Найти</button> <a href="'.self::esc(self::url('sessions')).'">Сбросить</a></form>';
        try{$data=(new AF_ACS_AcpReadModel($db))->sessions($filters,$mybb->get_input('acs_cursor'));$table=new Table;
            foreach(['Сессия / версия','Тема','Владелец','Состояние','Разрешённая навигация'] as $label)$table->construct_header($label);
            foreach($data['items'] as $row){$sid=(string)$row['id'];$tid=(string)$row['tid'];$table->construct_cell(self::esc('#'.$sid.' · v'.$row['session_version']));$table->construct_cell(self::esc($row['subject'].' · TID '.$tid));$table->construct_cell(self::esc($row['owner_uid']));$table->construct_cell(self::esc($row['status']));
                $link='../showthread.php?tid='.rawurlencode($tid);$links='<a href="'.self::esc($link).'">Открыть тему</a>';if($row['can_gm'])$links.=' · <a href="'.self::esc($link.'&acs_session='.$sid).'">GM-панель</a>';
                $links.=' · <a href="'.self::esc(self::url('diagnostics',['acs_inspect_session'=>$sid])).'">Inspect</a>';$table->construct_cell($links);$table->construct_row();}
            $table->output('До 50 разрешённых записей на странице');if(!$data['items'])echo '<p>На этой странице доступных записей нет.</p>';
            if($data['next_cursor']!==null){$args=['acs_cursor'=>$data['next_cursor']];foreach($filters as $k=>$v)$args['acs_filter_'.$k]=$v;echo '<p><a href="'.self::esc(self::url('sessions',$args)).'">Следующая страница</a></p>';}
            echo '<p>Native доступ проверяется для каждой темы; global ACP не делает администратора session GM. Числа скрытых/пропущенных сессий не выводятся.</p>';
        }catch(Throwable $e){self::failure($e, 'acp_sessions');}
    }
    public static function inspect():void
    {
        global $db,$mybb;$sid=$mybb->get_input('acs_inspect_session');$key=$mybb->get_input('acs_inspect_key');
        echo '<h3>Operation / recovery inspect · без изменений</h3>'.self::filters('diagnostics').'<label>Сессия # <input name="acs_inspect_session" inputmode="numeric" value="'.self::esc($sid).'" required></label> <label>Точный operation_key (необязательно) <input name="acs_inspect_key" maxlength="191" value="'.self::esc($key).'"></label><button>Проверить без записи</button></form>';
        if($sid===''){echo '<p>Выберите одну сессию. Весь журнал и payload операций при открытии ACP не загружаются.</p>';return;}
        try{$data=(new AF_ACS_AcpReadModel($db))->inspect($sid,$key,$mybb->get_input('acs_operation_after'));
            foreach(['operations'=>'Сохранённые операции','submissions'=>'Последние незавершённые публикации (до 20)','delivery'=>'Некритичные ожидающие доставки'] as $field=>$label){$rows=$data[$field];if(!$rows){echo '<p>'.self::esc($label).': записей в этой выборке нет.</p>';continue;}$table=new Table;foreach(array_keys($rows[0]) as $header)$table->construct_header(self::esc($header));foreach($rows as $row){foreach($row as $value)$table->construct_cell(self::esc($value));$table->construct_row();}$table->output(self::esc($label));}
            if($data['next_after']!==null)echo '<p><a href="'.self::esc(self::url('diagnostics',['acs_inspect_session'=>$sid,'acs_inspect_key'=>$key,'acs_operation_after'=>$data['next_after']])).'">Следующая страница операций</a></p>';
            if($data['can_gm'])echo '<p><a href="../misc.php?action=acs_recovery&amp;session_id='.self::esc($sid).'">Открыть существующий session recovery</a> · <a href="../misc.php?action=acs_loot&amp;session_id='.self::esc($sid).'&amp;completion_tab=recovery">Обязательства завершения</a></p>';
            else echo '<p>Global inspect не предоставляет право recovery. Обратитесь к текущему GM сессии.</p>';
            echo '<p>Inspect не вызывает lookup владельцев, dispatch, retry, compensation или Resolver. Неподтверждённая операция не названа успешной. Recovery остаётся на существующих типизированных services с их правами/версиями/audit.</p>';
        }catch(Throwable $e){self::failure($e);}
    }
    public static function errors(AF_ACS_Diagnostics $diagnostics):array
    {
        global $db,$mybb;$id=$mybb->get_input('acs_error_id');echo self::filters('diagnostics').'<label>Найти error_id <input name="acs_error_id" maxlength="32" value="'.self::esc($id).'"></label><button>Inspect</button></form>';
        try{$rows=(new AF_ACS_AcpReadModel($db))->errors($id);if($id==='')foreach($diagnostics->recentErrors() as $row)if(($row['category']??'')==='LIFECYCLE')$rows[]=$row;return $rows;}catch(Throwable $e){self::failure($e);return $id===''?$diagnostics->recentErrors():[];}
    }
}
