<?php
if(!defined('IN_MYBB')){die('No direct access');}
require_once __DIR__.'/../Application/QueryService.php';
require_once __DIR__.'/../Application/BuilderService.php';

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
    public static function dispatchBuilder():never
    {
        global $mybb,$db;
        header('Content-Type: application/json; charset=UTF-8');header('Cache-Control: private, no-store');header('X-Content-Type-Options: nosniff');
        try{
            if(!af_is_addon_enabled('advancedcombatsystem')||!empty($GLOBALS['af_acs_deactivated']))throw new RuntimeException('ACS_DISABLED');
            if((int)($_SERVER['CONTENT_LENGTH']??0)>131072)throw new RuntimeException('builder_input_invalid');
            $service=new AF_ACS_BuilderService(new AF_ACS_EncounterRepository($db),new AF_ACS_SessionAccess);
            if($mybb->get_input('op')==='builder_context'){
                if($mybb->request_method!=='get')throw new RuntimeException('builder_input_invalid');
                $data=$service->context($mybb->get_input('session_id'),$mybb->get_input('encounter_id'),$mybb->get_input('turn_id'));
            }else{
                if(array_diff(array_keys($_POST),['action','addon','op','my_post_key','session_id','encounter_id','turn_id','scope_token','actions','scenario']))throw new RuntimeException('builder_input_invalid');
                $input=[];foreach(['session_id','encounter_id','turn_id','scope_token','scenario'] as $f)$input[$f]=$mybb->get_input($f);
                $raw=$mybb->get_input('actions');if(strlen($raw)>65536)throw new RuntimeException('builder_input_invalid');
                $input['actions']=json_decode($raw,true,32,JSON_THROW_ON_ERROR);$data=$service->preview($input);
            }
            $response=['ok'=>true,'data'=>$data];
        }catch(Throwable $e){http_response_code(422);$response=['ok'=>false,'error'=>['code'=>'builder_unavailable','message'=>AF_ACS_BuilderService::message($e)]];}
        echo json_encode($response,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);exit;
    }
    /** The ATF provider calls this only after explicit acs_builder navigation and native topic checks. */
    public static function renderBuilder(string $sid,string $tid,?AF_ACS_BuilderService $builder=null):string
    {
        global $db,$mybb;
        try{
            if($builder!==null){
                $context=$builder->context($sid,$mybb->get_input('acs_encounter'));
                if($context['tid']!==$tid)throw new RuntimeException('ACS_DRAFT_UNAVAILABLE');
            }else{
            $repo=new AF_ACS_EncounterRepository($db);$access=new AF_ACS_SessionAccess;
            $view=(new AF_ACS_SessionService($repo,$access))->view($sid);
            if($view['session']['tid']!==$tid)throw new RuntimeException('ACS_DRAFT_UNAVAILABLE');
            $eid=$mybb->get_input('acs_encounter');
            if($eid==='')$eid=$repo->builderEncounter($sid)??'';
            $context=(new AF_ACS_BuilderService($repo,$access))->context($sid,$eid);
            }
            if(!function_exists('af_frontend_asset_allowed')||!af_frontend_asset_allowed('advancedcombatsystem','builder',null,['has_acs_builder'=>true]))throw new RuntimeException('builder_assets_unavailable');
            $config=['context'=>$context,'post_key'=>$mybb->post_code,'endpoint'=>'xmlhttp.php?action=af_addon_api&addon=advancedcombatsystem'];
            $json=json_encode($config,JSON_THROW_ON_ERROR|JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_UNESCAPED_UNICODE);
            $base=self::esc(rtrim((string)($mybb->settings['bburl']??''),'/').'/inc/plugins/advancedfunctionality/addons/advancedcombatsystem/assets/');
            return '<link rel="stylesheet" href="'.$base.'builder.css?v=0180"><section class="af-acs-builder" aria-label="Подготовка боевого хода" data-acs-builder><h3>Подготовка хода ACS</h3><p class="acs-notice">Боевая публикация пока не подключена. Предпросмотр условный: бросков и списания HP/AP нет.</p><div data-part="status" role="status" aria-live="polite"></div><div data-part="overview"></div><label>Группа<select data-field="group"></select></label><div class="acs-grid"><label>Персонаж или питомец<select data-field="actor"></select></label><label>Способность<select data-field="ability"></select></label><label>Цель<select data-field="target"></select></label></div><p data-part="action-info"></p><p data-part="parameters">Текущие поддержанные способности используют закреплённые параметры KB. Произвольные параметры расчёта не принимаются.</p><button type="button" data-command="add">Добавить действие</button><button type="button" data-command="cancel-edit" hidden>Отменить редактирование</button><h4>Подготовленные действия</h4><ol data-part="queue"></ol><p data-part="queue-total"></p><label>Литературный черновик (BBCode)<textarea data-field="prose" data-af-ae-skip="1" rows="5" maxlength="65536"></textarea></label><div class="acs-controls"><button type="button" data-command="save">Сохранить черновик</button><button type="button" data-command="restore">Восстановить серверный черновик</button><button type="button" data-command="refresh">Обновить сведения</button></div><h4>Условный предпросмотр</h4><label>Сценарий для последовательности<select data-field="scenario"><option value="hit">Если все попадания без крита</option><option value="crit">Если все попадания критические</option><option value="miss">Если все промахи</option></select></label><button type="button" data-command="preview">Рассчитать последовательность без бросков</button><div data-part="preview" aria-live="polite"></div><p><button type="button" disabled>Отправить боевой пост</button> Боевая публикация пока не подключена.</p><script type="application/json" data-acs-builder-config>'.$json.'</script></section><script defer src="'.$base.'builder.js?v=0180"></script>';
        }catch(Throwable $e){return '<p class="af-acs">'.self::esc(AF_ACS_BuilderService::message($e)).'</p>';}
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
