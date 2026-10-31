<?php
if(!defined('IN_MYBB')){die('No direct access');}
require_once __DIR__.'/../Application/QueryService.php';

/** Read-only conditional preview; errors deliberately bypass the command diagnostic SQL writer. */
final class AF_ACS_BuilderController
{
    private static function service():AF_ACS_QueryService
    {
        global $db;if(!af_is_addon_enabled('advancedcombatsystem')||!empty($GLOBALS['af_acs_deactivated']))throw new RuntimeException('ACS_DISABLED');
        return new AF_ACS_QueryService(new AF_ACS_EncounterRepository($db),new AF_ACS_SessionAccess);
    }
    private static function error(Throwable $e):array
    {
        $code=$e instanceof AF_ACS_FormulaError?$e->diagnosticCode:$e->getMessage();
        if(!preg_match('/\A(?:ACS_[A-Z_]+|[a-z][a-z0-9_]{1,95})\z/D',$code))$code='preview_unavailable';
        $message=match($code){'preview_version_conflict','ACS_VERSION_CONFLICT'=>'Состояние изменилось. Обновите панель и выберите действие заново.',
            'preview_target_forbidden','ACS_GM_REQUIRED','ACS_SESSION_UNAVAILABLE','ACS_AP_ACCESS_DENIED','ACS_CSRF_REQUIRED','ACS_POST_REQUIRED'=>'Нет доступа к этому предпросмотру или запрос не подтверждён.',
            'effects_cost_unaffordable'=>'Недостаточно AP или ресурсов.', 'resolver_scenario_impossible'=>'Выбранный условный исход невозможен при текущих вероятностях.',
            'preview_turn_unavailable'=>'Нужен открытый pending-ход с уже выполненной границей начала.',
            default=>'Расчёт заблокирован: данные или контракт механики пока не поддерживаются. Код: '.$code};
        return ['ok'=>false,'error'=>['code'=>$code,'message'=>$message,'error_id'=>bin2hex(random_bytes(16))],'persistent'=>false];
    }
    private static function input():array
    {
        global $mybb;
        if(!in_array($mybb->get_input('mode'),['','preview'],true))throw new AF_ACS_FormulaError('resolver_mode_unsupported');
        if(array_diff(array_keys($_POST),['session_id','encounter_id','actor_id','target_id','ability_key','scenario','preview_versions','my_post_key','acs_preview','action','addon','op','mode']))throw new AF_ACS_FormulaError('preview_input_invalid');
        $input=[];foreach(['session_id','encounter_id','actor_id','target_id','ability_key','scenario'] as $key)$input[$key]=$mybb->get_input($key);
        $json=$mybb->get_input('preview_versions');if(strlen($json)>4096)throw new RuntimeException('ACS_REQUEST_TOO_LARGE');$input['versions']=json_decode($json,true,8,JSON_THROW_ON_ERROR);return $input;
    }
    public static function dispatch():never
    {
        try{if((int)($_SERVER['CONTENT_LENGTH']??0)>16384)throw new RuntimeException('ACS_REQUEST_TOO_LARGE');$result=['ok'=>true,'data'=>self::service()->preview(self::input())];}
        catch(Throwable $e){$result=self::error($e);$code=$result['error']['code'];http_response_code(in_array($code,['ACS_GM_REQUIRED','ACS_SESSION_UNAVAILABLE','ACS_AP_ACCESS_DENIED','ACS_CSRF_REQUIRED','ACS_POST_REQUIRED','preview_target_forbidden'],true)?403:($code==='preview_version_conflict'?409:422));}
        header('Content-Type: application/json; charset=UTF-8');header('Cache-Control: private, no-store');echo json_encode($result,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);exit;
    }
    private static function esc(mixed $s):string{return htmlspecialchars_uni((string)$s);}
    public static function render(string $sid,string $eid,string $tid,?AF_ACS_QueryService $query=null):void
    {
        global $mybb;
        try{
            $q=$query??self::service();$selection=$q->select($sid,$eid);$actor=$mybb->get_input('preview_actor');$target=$mybb->get_input('preview_target');
            echo '<p>Предпросмотр для мастера. Выбирается условный исход; бросков и изменений состояния нет. Управление чужим персонажем не предоставляется.</p>';
            echo '<form method="get" action="showthread.php">';foreach(['tid'=>$tid,'acs_session'=>$sid,'acs_encounter'=>$eid,'acs_tab'=>'preview'] as $k=>$v)echo '<input type="hidden" name="'.self::esc($k).'" value="'.self::esc($v).'">';
            foreach(['preview_actor'=>['Атакующий',$selection['actors'],$actor],'preview_target'=>['Цель',$selection['targets'],$target]] as $field=>[$label,$rows,$chosen]){
                echo '<label>'.self::esc($label).'<select name="'.$field.'"><option value="">Выберите</option>';foreach($rows as $row)echo '<option value="'.self::esc($row['id']).'"'.($row['id']===$chosen?' selected':'').'>'.self::esc($row['name']).'</option>';echo '</select></label>';
            }echo '<button>Выбрать участников</button></form>';if($actor===''||$target==='')return;
            $prepared=$q->inspect($sid,$eid,AF_ACS_Repository::integer($actor),AF_ACS_Repository::integer($target));
            echo '<form method="post">';foreach(['session_id'=>$sid,'encounter_id'=>$eid,'actor_id'=>$actor,'target_id'=>$target,'my_post_key'=>$mybb->post_code,'preview_versions'=>json_encode($prepared['versions'],JSON_THROW_ON_ERROR),'acs_preview'=>'1'] as $k=>$v)echo '<input type="hidden" name="'.self::esc($k).'" value="'.self::esc($v).'">';
            echo '<label>Действие<select name="ability_key">';foreach($prepared['choices'] as $choice){if($choice['supported'])echo '<option value="'.self::esc($choice['key']).'">'.self::esc($choice['label']).'</option>';else echo '<option disabled>'.self::esc($choice['label'].' — '.$choice['reason']).'</option>';}
            echo '</select></label><label>Условный сценарий<select name="scenario"><option value="hit">Попадание без крита</option><option value="crit">Попадание с критом</option><option value="miss">Промах</option></select></label><button>Рассчитать без броска</button></form>';
            if(!$prepared['choices'])echo '<p>Нет сохранённых поддерживаемых способностей. Базовый урон из ATK не подставляется вместо определения KB.</p>';
            if($mybb->request_method==='post'&&$mybb->get_input('acs_preview')==='1'){
                $data=$q->preview(self::input());echo '<h4>Условный прогноз — не событие боя</h4><pre>'.self::esc(json_encode($data,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)).'</pre>';
            }
        }catch(Throwable $e){echo '<p>'.self::esc(self::error($e)['error']['message']).'</p>';}
    }
}
