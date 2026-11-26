<?php
if (!defined('IN_MYBB') || !defined('IN_ADMINCP') || !IN_ADMINCP) { die('No direct access'); }

require_once __DIR__.'/OwnerProtocol.php';
require_once __DIR__.'/OwnerSchema.php';
require_once __DIR__.'/../../advancedcombatsystem/modules/Infrastructure/TransactionManager.php';

final class AF_ADVINV_OwnerSchemaAdmin
{
    public static function render(string $url): string
    {
        global $mybb,$db;
        $uid=(int)($mybb->user['uid']??0);
        if ($uid<1 || empty($mybb->usergroup['cancp']) || (!(function_exists('is_super_admin') && is_super_admin($uid))
            && !in_array($mybb->admin['permissions']['advancedfunctionality']['index']??null,[1,'1',true],true))) {
            return '<div class="error">Недостаточно прав для подготовки схемы.</div>';
        }
        $link=$db instanceof mysqli ? $db : ($db->write_link??null);
        if (!$link instanceof mysqli) return '<div class="error">BLOCKED · INV_MYSQLI_REQUIRED</div>';
        $status='';
        if ($mybb->request_method==='post') {
            if (!verify_post_check($mybb->get_input('my_post_key'),true) || $mybb->get_input('confirm_schema')!=='yes') {
                return '<div class="error">BLOCKED · Требуется подтверждение плана.</div>';
            }
            $audit=static function (string $event,string $component): void {
                log_admin_action('advinv_acs_schema',$event,$component);
            };
            try {
                $result=AF_ACS_TransactionManager::databaseSettings($link,static fn()=>AF_ADVINV_OwnerSchema::deploy(
                    $link,TABLE_PREFIX,$mybb->get_input('schema_plan'),$audit
                ));
                $status=(!$result['create'] && !$result['blocked']) ? '<p role="status">Развёртывание проверено.</p>' : '';
            } catch (Throwable $error) {
                $code=self::code($error);
                $audit('blocked',$code);
                $status='<div class="error">BLOCKED · '.self::esc($code).'. План перечитан ниже.</div>';
            }
        }
        try {
            $plan=AF_ADVINV_OwnerSchema::plan(new AF_ADVINV_OwnerProtocol($link));
        } catch (Throwable $error) {
            return $status.'<div class="error">BLOCKED · '.self::esc(self::code($error)).'</div>';
        }
        $ready=!$plan['create'] && !$plan['blocked'];
        $html=$status.'<h2>Подготовка схемы для ACS</h2><p><strong>'.($ready?'READY':'BLOCKED').'</strong></p>'
            .'<table class="general"><thead><tr><th>Таблица</th><th>Наличие</th><th>Движок</th><th>Обязательные колонки</th>'
            .'<th>Индексы</th><th>План и диагностика</th></tr></thead><tbody>';
        foreach ($plan['tables'] as $row) {
            $action=!$row['exists']?'Создать':($row['issues']?'Требуется ручная миграция':'Готова');
            $html.='<tr><td>'.self::esc($row['name']).'</td><td>'.($row['exists']?'Есть':'Отсутствует').'</td><td>'
                .self::esc($row['engine']??'—').'</td><td>'.self::checks($row['columns'])
                .'</td><td>'.self::checks($row['indexes']).'</td><td>'.self::esc($action)
                .($row['issues']?'<br>'.self::esc(implode(', ',$row['issues'])):'').'</td></tr>';
        }
        $html.='</tbody></table>';
        if ($plan['blocked']) {
            $html.='<p>Сделайте резервную копию и сверяйте отмеченные компоненты с DDL AdvancedInventory на копии базы. '
                .'После проверки данных и зависимостей примените согласованную миграцию в режиме обслуживания, затем откройте этот план снова. '
                .'Для отсутствующих колонок prepared_binding в существующей таблице резервов предусмотрен OwnerSchema::upgradePrepared().</p>';
        } elseif ($plan['create']) {
            $html.='<form method="post" action="'.self::esc($url).'">'
                .'<input type="hidden" name="my_post_key" value="'.self::esc($mybb->post_code).'">'
                .'<input type="hidden" name="schema_plan" value="'.self::esc($plan['fingerprint']).'">'
                .'<label><input type="checkbox" name="confirm_schema" value="yes" required> Подтверждаю создание таблиц из показанного плана</label> '
                .'<button type="submit">Проверить и подготовить схему</button></form>';
        }
        return $html;
    }

    private static function checks(array $values): string
    {
        $labels=[];
        foreach ($values as $key=>$ready) $labels[]=$key.' '.($ready?'✓':'✗');
        return $labels ? self::esc(implode(', ',$labels)) : '—';
    }

    private static function code(Throwable $error): string
    {
        $code=$error->getMessage();
        return preg_match('/\A(?:ACS|INV)_[A-Z0-9_]{1,90}\z/D',$code) ? $code : 'INV_SCHEMA_SETUP_FAILED';
    }

    private static function esc(string $text): string { return htmlspecialchars_uni($text); }
}
