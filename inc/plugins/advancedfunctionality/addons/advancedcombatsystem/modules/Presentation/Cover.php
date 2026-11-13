<?php
if(!defined('IN_MYBB')){die('No direct access');}
final class AF_ACS_CoverPage
{
    private static function esc(mixed $v):string{return htmlspecialchars((string)$v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
    public static function render(array $v,string $url,AF_ACS_CoverService $service):void
    {
        global $mybb;$actors=array_column($v['participants'],'entity_id');$assigned=$service->view($v['session']['id'],$v['encounter']['id'],$actors);$rows=[];foreach($assigned as $r)$rows[$r['cover_entity_id'].':'.$r['protected_entity_id']]=$r;
        echo '<h4>Назначения укрытий</h4>';foreach($actors as $actor)foreach($service->catalog($v['session']['id'],$v['encounter']['id'],$actor) as $choice){preg_match('/\Acover:(enter|leave):([1-9][0-9]*)\z/D',$choice['key'],$m);$r=$rows[$m[2].':'.$actor]??null;$fields=['acs_encounter_op'=>'cover_assignment','tid'=>$v['session']['tid'],'session_id'=>$v['session']['id'],'encounter_id'=>$v['encounter']['id'],'expected_version'=>$v['session']['session_version'],'expected_encounter_version'=>$v['encounter']['encounter_version'],'cover_entity_id'=>$m[2],'protected_entity_id'=>$actor,'expected_assignment_version'=>$r['assignment_version']??0,'status'=>$m[1]==='enter'?'active':'inactive','my_post_key'=>$mybb->post_code,'operation_key'=>bin2hex(random_bytes(16))];echo '<form method="post" action="'.self::esc($url).'">';foreach($fields as $k=>$value)echo '<input type="hidden" name="'.self::esc($k).'" value="'.self::esc($value).'">';echo '<span>'.self::esc($actor.' · '.$choice['label']).'</span> <label>Причина <input name="reason" required maxlength="1000"></label> <button type="submit">Сохранить</button></form>';}
    }
}
